mod discovery;

use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine};
use discovery::{discover_files, read_file_with_hash_under_root, DumpFile};
use keyring::{Entry, Error as KeyringError};
use notify::{Event, EventKind, RecommendedWatcher, RecursiveMode, Watcher};
use rand::{distributions::Alphanumeric, Rng};
use reqwest::{multipart, Client, StatusCode};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use std::{
    collections::{BTreeSet, HashMap},
    fs,
    path::{Path, PathBuf},
    sync::atomic::{AtomicU64, Ordering},
    sync::{Arc, Mutex},
    time::Duration,
};
use tauri::{AppHandle, Emitter, Manager, Runtime, WindowEvent};
use tokio::{
    io::{AsyncReadExt, AsyncWriteExt},
    net::TcpListener,
    sync::{mpsc, watch},
    time::timeout,
};
use url::Url;

const KEYRING_SERVICE: &str = "dev.flynn.desktop";
const KEYRING_USER: &str = "desktop-access-token";
const CALLBACK_URI: &str = "http://127.0.0.1:51337/oauth/callback";
const HTTP_CONNECT_TIMEOUT: Duration = Duration::from_secs(5);
const HTTP_REQUEST_TIMEOUT: Duration = Duration::from_secs(20);
const CALLBACK_READ_TIMEOUT: Duration = Duration::from_secs(10);
const MAX_CALLBACK_BYTES: usize = 8192;

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(default, rename_all = "camelCase")]
struct Config {
    api_base_url: String,
    credential_api_url: Option<String>,
    authenticated_user_id: Option<String>,
    wow_root: Option<PathBuf>,
    auto_sync: bool,
    minimize_to_tray: bool,
    launch_at_login: bool,
    disabled_scopes: BTreeSet<String>,
    successful_hashes: HashMap<String, String>,
    pending_dumps: Vec<PendingDump>,
    submission_attempts: Vec<SubmissionAttempt>,
    activity: Vec<ActivityItem>,
    durability_warning: Option<String>,
    credential_cleanup_warning: Option<String>,
}

impl Default for Config {
    fn default() -> Self {
        Self {
            api_base_url: "http://127.0.0.1:8000".into(),
            credential_api_url: None,
            authenticated_user_id: None,
            wow_root: discovery::detect_installations().into_iter().next(),
            auto_sync: false,
            minimize_to_tray: true,
            launch_at_login: false,
            disabled_scopes: BTreeSet::new(),
            successful_hashes: HashMap::new(),
            pending_dumps: Vec::new(),
            submission_attempts: Vec::new(),
            activity: Vec::new(),
            durability_warning: None,
            credential_cleanup_warning: None,
        }
    }
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Account {
    id: Value,
    name: String,
    email: String,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct ActivityItem {
    id: String,
    file: String,
    status: String,
    message: String,
    at: String,
    characters_seen: Option<u64>,
    characters_updated: Option<u64>,
    partial: bool,
    results: Vec<CharacterResult>,
}

#[derive(Clone, Debug, Serialize, Deserialize, Default)]
#[serde(rename_all = "camelCase")]
pub struct CharacterResult {
    identity: Option<Value>,
    status: Option<String>,
    code: Option<String>,
    message: Option<String>,
}

#[derive(Clone, Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct DesktopStatus {
    authenticated: bool,
    account: Option<Account>,
    api_base_url: String,
    wow_root: Option<String>,
    auto_sync: bool,
    minimize_to_tray: bool,
    launch_at_login: bool,
    detected_installations: Vec<String>,
    files: Vec<DumpFile>,
    activity: Vec<ActivityItem>,
    submission_attempts: Vec<VisibleSubmissionAttempt>,
    last_upload_at: Option<String>,
    durability_warning: Option<String>,
    credential_cleanup_warning: Option<String>,
}

#[derive(Clone, Debug, Serialize)]
#[serde(rename_all = "camelCase")]
struct VisibleSubmissionAttempt {
    file: String,
    state: String,
    message: String,
}

#[derive(Clone, Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
struct UploadReceipt {
    dump_id: String,
    status: String,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
struct PendingDump {
    dump_id: String,
    file: String,
    hash: String,
    api_base_url: String,
    user_id: String,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
struct SubmissionAttempt {
    file: String,
    hash: String,
    api_base_url: String,
    user_id: String,
    session_key: String,
    state: String,
}

#[derive(Clone, Debug)]
struct SessionIdentity {
    generation: u64,
    api_url: String,
    user_id: String,
    token: String,
    key: String,
}

#[derive(Clone, Debug, Serialize)]
#[serde(rename_all = "camelCase")]
struct SyncOutcome {
    path: String,
    status: String,
    message: String,
    partial: bool,
}

struct AppState {
    config_path: PathBuf,
    config: tokio::sync::Mutex<Config>,
    save_lock: tokio::sync::Mutex<()>,
    in_flight: tokio::sync::Mutex<BTreeSet<String>>,
    dirty_paths: tokio::sync::Mutex<BTreeSet<String>>,
    watcher: Mutex<Option<RecommendedWatcher>>,
    file_events: mpsc::UnboundedSender<PathBuf>,
    client: Client,
    credentials: Arc<dyn CredentialStore>,
    session_generation: AtomicU64,
    session_events: watch::Sender<u64>,
    session_mutation: tokio::sync::Mutex<()>,
    dispatch_gate: Mutex<()>,
}

impl AppState {
    fn load(
        config_path: PathBuf,
        file_events: mpsc::UnboundedSender<PathBuf>,
        credentials: Arc<dyn CredentialStore>,
    ) -> Self {
        let config = fs::read(&config_path)
            .ok()
            .and_then(|bytes| serde_json::from_slice::<Config>(&bytes).ok())
            .unwrap_or_default();
        let mut config = config;
        for attempt in &mut config.submission_attempts {
            if attempt.state == "submitting" {
                attempt.state = "ambiguous".into();
            }
        }
        let (session_events, _) = watch::channel(0);
        Self {
            config_path,
            config: tokio::sync::Mutex::new(config),
            save_lock: tokio::sync::Mutex::new(()),
            in_flight: tokio::sync::Mutex::new(BTreeSet::new()),
            dirty_paths: tokio::sync::Mutex::new(BTreeSet::new()),
            watcher: Mutex::new(None),
            file_events,
            client: Client::builder()
                .connect_timeout(HTTP_CONNECT_TIMEOUT)
                .timeout(HTTP_REQUEST_TIMEOUT)
                .build()
                .expect("valid bounded Flynn HTTP client configuration"),
            credentials,
            session_generation: AtomicU64::new(0),
            session_events,
            session_mutation: tokio::sync::Mutex::new(()),
            dispatch_gate: Mutex::new(()),
        }
    }

    async fn save(&self) -> Result<(), String> {
        self.save_with_parent_sync(sync_parent_directory).await
    }

    async fn save_with_parent_sync<F>(&self, sync_parent: F) -> Result<(), String>
    where
        F: FnOnce(&Path) -> std::io::Result<()>,
    {
        self.save_with_hooks(sync_parent, || Ok(())).await
    }

    async fn save_with_hooks<F, G>(&self, sync_parent: F, before_rename: G) -> Result<(), String>
    where
        F: FnOnce(&Path) -> std::io::Result<()>,
        G: FnOnce() -> std::io::Result<()>,
    {
        let _save_guard = self.save_lock.lock().await;
        // Take the snapshot only after entering the serialized write section.
        // Otherwise an older caller can queue behind a newer save and replace
        // its submission/pending state with a stale snapshot.
        let mut config = self.config.lock().await.clone();
        // A successful parent sync supersedes any warning from an earlier save.
        config.durability_warning = None;
        let warning = self.write_config_with_hooks(&config, sync_parent, before_rename)?;
        if let Some(warning) = warning {
            {
                let mut current = self.config.lock().await;
                current.durability_warning = Some(warning.clone());
                config = current.clone();
            }
            // The first rename has committed. Persist the warning in a second
            // atomic write so it remains visible after an ordinary restart.
            // Do not claim this warning-bearing rename is directory-synced:
            // the warning records that durability could not be confirmed.
            if let Err(error) = self.write_config_with_hooks(&config, |_| Ok(()), || Ok(())) {
                let warning =
                    format!("{warning} The warning itself could not be persisted: {error}");
                self.config.lock().await.durability_warning = Some(warning);
            }
        } else {
            self.config.lock().await.durability_warning = None;
        }
        Ok(())
    }

    fn write_config_with_hooks<F, G>(
        &self,
        config: &Config,
        sync_parent: F,
        before_rename: G,
    ) -> Result<Option<String>, String>
    where
        F: FnOnce(&Path) -> std::io::Result<()>,
        G: FnOnce() -> std::io::Result<()>,
    {
        let parent = self
            .config_path
            .parent()
            .ok_or("Settings path has no parent directory.")?;
        fs::create_dir_all(parent).map_err(|error| error.to_string())?;
        let bytes = serde_json::to_vec_pretty(&config).map_err(|error| error.to_string())?;
        let temporary = parent.join(format!(".settings-{}.tmp", random_secret(16)));
        let write_result = (|| {
            use std::io::Write;
            let mut file = fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .open(&temporary)?;
            file.write_all(&bytes)?;
            file.sync_all()?;
            before_rename()?;
            fs::rename(&temporary, &self.config_path)?;
            Ok::<(), std::io::Error>(())
        })();
        if write_result.is_err() {
            let _ = fs::remove_file(&temporary);
        }
        write_result.map_err(|error| format!("Could not save desktop settings: {error}"))?;

        // Rename is the commit point. If syncing the containing directory
        // fails, the new config is already visible on disk; report a warning
        // rather than rolling credentials/session memory back against it.
        match sync_parent(parent) {
            Ok(()) => Ok(None),
            Err(error) => Ok(Some(format!("Settings were saved, but Flynn could not confirm the containing directory was synced; the latest settings may not survive a sudden power loss: {error}"))),
        }
    }

    async fn files(&self) -> Vec<DumpFile> {
        let config = self.config.lock().await;
        config
            .wow_root
            .as_ref()
            .map(|root| discover_files(root, &config.disabled_scopes))
            .unwrap_or_default()
    }
}

impl AppState {
    fn invalidate_session(&self) -> u64 {
        let _dispatch = self
            .dispatch_gate
            .lock()
            .expect("session dispatch lock is not poisoned");
        let generation = self.session_generation.fetch_add(1, Ordering::SeqCst) + 1;
        self.session_events.send_replace(generation);
        generation
    }

    fn session_is_current(&self, session: &SessionIdentity) -> bool {
        self.session_generation.load(Ordering::SeqCst) == session.generation
    }

    async fn session_identity_is_current(&self, session: &SessionIdentity) -> bool {
        if !self.session_is_current(session) {
            return false;
        }
        let (api_url, credential_api_url, user_id) = {
            let config = self.config.lock().await;
            (
                config.api_base_url.clone(),
                config.credential_api_url.clone(),
                config.authenticated_user_id.clone(),
            )
        };
        if api_url != session.api_url
            || credential_api_url.as_deref() != Some(session.api_url.as_str())
            || (!session.user_id.is_empty() && user_id.as_deref() != Some(session.user_id.as_str()))
        {
            return false;
        }
        self.credentials
            .get(&session.api_url)
            .is_ok_and(|token| token.as_deref() == Some(session.token.as_str()))
            && self.session_is_current(session)
    }
}

async fn send_fenced(
    state: &AppState,
    request: reqwest::RequestBuilder,
    session: &SessionIdentity,
    cancelled: &mut watch::Receiver<u64>,
) -> Result<reqwest::Response, String> {
    use std::{
        future::{poll_fn, Future},
        task::Poll,
    };
    let mut send = Box::pin(request.send());
    let first_poll = poll_fn(
        |context| -> Poll<Result<Option<Result<reqwest::Response, reqwest::Error>>, String>> {
            let _dispatch = state
                .dispatch_gate
                .lock()
                .map_err(|_| "Session dispatch lock was poisoned.".to_string());
            let _dispatch = match _dispatch {
                Ok(guard) => guard,
                Err(error) => return Poll::Ready(Err(error)),
            };
            if !state.session_is_current(session) {
                return Poll::Ready(Err(
                    "SESSION_CHANGED: Flynn session changed before request dispatch.".into(),
                ));
            }
            match send.as_mut().poll(context) {
                Poll::Ready(result) => Poll::Ready(Ok(Some(result))),
                Poll::Pending => Poll::Ready(Ok(None)),
            }
        },
    )
    .await?;
    let result = if let Some(result) = first_poll {
        result
    } else {
        tokio::select! {
            biased;
            _ = cancelled.changed() => return Err("SESSION_CHANGED: Flynn session changed; operation cancelled.".into()),
            result = &mut send => result,
        }
    };
    result.map_err(|error| error.to_string())
}

async fn active_token(state: &AppState, api_url: &str) -> Result<Option<String>, String> {
    let active_for_api = credential_matches_api(
        state.config.lock().await.credential_api_url.as_deref(),
        api_url,
    );
    if active_for_api {
        credential_get(state.credentials.as_ref(), api_url)
    } else {
        Ok(None)
    }
}

// Credential-store implementations (including platform keyring backends) may
// return diagnostic strings containing implementation details or sensitive
// values. Never expose those strings to the UI; keep failures actionable with
// stable operation-specific codes instead.
fn credential_store_error(operation: &'static str) -> String {
    match operation {
        "read" => "CREDENTIAL_STORE_READ_FAILED: Flynn could not access the OS credential store. Unlock or check access to your system keychain/credential manager and try again.".into(),
        "write" => "CREDENTIAL_STORE_WRITE_FAILED: Flynn could not securely save the login in the OS credential store. Unlock or check access to your system keychain/credential manager and try again.".into(),
        "delete" => "CREDENTIAL_STORE_DELETE_FAILED: Flynn could not remove a credential from the OS credential store. Check access to your system keychain/credential manager and try again.".into(),
        _ => "CREDENTIAL_STORE_OPERATION_FAILED: Flynn could not complete an OS credential-store operation. Check access to your system keychain/credential manager and try again.".into(),
    }
}

fn credential_get(
    credentials: &dyn CredentialStore,
    api_url: &str,
) -> Result<Option<String>, String> {
    credentials
        .get(api_url)
        .map_err(|_| credential_store_error("read"))
}

fn credential_set(
    credentials: &dyn CredentialStore,
    api_url: &str,
    token: &str,
) -> Result<(), String> {
    credentials
        .set(api_url, token)
        .map_err(|_| credential_store_error("write"))
}

fn credential_delete(credentials: &dyn CredentialStore, api_url: &str) -> Result<(), String> {
    credentials
        .delete(api_url)
        .map_err(|_| credential_store_error("delete"))
}

fn credential_matches_api(credential_api: Option<&str>, active_api: &str) -> bool {
    credential_api == Some(active_api)
}

async fn clear_active_token(
    state: &AppState,
    api_url: &str,
    expected_token: &str,
    generation: u64,
) -> Result<bool, String> {
    Ok(
        clear_active_token_generation(state, api_url, expected_token, generation)
            .await?
            .is_some(),
    )
}

async fn clear_active_token_generation(
    state: &AppState,
    api_url: &str,
    expected_token: &str,
    generation: u64,
) -> Result<Option<u64>, String> {
    clear_active_token_generation_with_save_failure(
        state,
        api_url,
        expected_token,
        generation,
        None,
    )
    .await
}

async fn clear_active_token_generation_with_save_failure(
    state: &AppState,
    api_url: &str,
    expected_token: &str,
    generation: u64,
    pre_rename_failure: Option<String>,
) -> Result<Option<u64>, String> {
    let _mutation = state.session_mutation.lock().await;
    if state.session_generation.load(Ordering::SeqCst) != generation
        || credential_get(state.credentials.as_ref(), api_url)
            .ok()
            .flatten()
            .as_deref()
            != Some(expected_token)
    {
        return Ok(None);
    }
    let previous = state.config.lock().await.clone();
    if previous.api_base_url != api_url
        || previous.credential_api_url.as_deref() != Some(api_url)
        || state.session_generation.load(Ordering::SeqCst) != generation
    {
        return Ok(None);
    }

    // Commit the signed-out binding before deleting the OS credential. A
    // pre-rename config failure therefore leaves the credential and persisted
    // authenticated binding together, never half-cleared.
    state.invalidate_session();
    let mut candidate = previous.clone();
    candidate.credential_api_url = None;
    candidate.authenticated_user_id = None;
    candidate.successful_hashes.clear();
    candidate.credential_cleanup_warning = None;
    *state.config.lock().await = candidate;
    let save_result = if let Some(message) = pre_rename_failure {
        state
            .save_with_hooks(sync_parent_directory, move || {
                Err(std::io::Error::other(message))
            })
            .await
    } else {
        state.save().await
    };
    if let Err(error) = save_result {
        *state.config.lock().await = previous;
        return Err(format!("Could not save the signed-out Flynn session; the OS credential was retained and the previous local session remains configured. {error}"));
    }
    let cleared_generation = state.session_generation.load(Ordering::SeqCst);
    if let Err(delete_error) = credential_delete(state.credentials.as_ref(), api_url) {
        let warning = format!("Flynn signed you out locally, but the expired credential could not be removed from the OS credential store. It is not bound to an active session and will not be used. {delete_error}");
        state.config.lock().await.credential_cleanup_warning = Some(warning.clone());
        if let Err(save_error) = state.save().await {
            let warning =
                format!("{warning} The cleanup warning could not be persisted: {save_error}");
            state.config.lock().await.credential_cleanup_warning = Some(warning.clone());
            return Err(warning);
        }
        return Err(warning);
    }
    Ok(Some(cleared_generation))
}

/// Installs credentials and settings while the caller holds `session_mutation`.
/// Keep this helper separate so command flows that already hold the mutation
/// lock cannot accidentally reacquire the non-reentrant Tokio mutex.
async fn install_authenticated_session_locked(
    state: &AppState,
    api_url: &str,
    token: &str,
    user_id: &str,
) -> Result<(), String> {
    let previous = state.config.lock().await.clone();
    let previous_api = previous.credential_api_url.clone();
    let new_credential_backup = credential_get(state.credentials.as_ref(), api_url)?;
    let old_credential_backup = previous_api
        .as_deref()
        .filter(|old_api| *old_api != api_url)
        .map(|old_api| credential_get(state.credentials.as_ref(), old_api))
        .transpose()?;

    // Fence requests using the current credential before replacing/deleting
    // keyring entries. A failed cross-server transition leaves the old config
    // and old credential active rather than silently switching half-way.
    state.invalidate_session();
    credential_set(state.credentials.as_ref(), api_url, token)?;
    if let Some(old_api) = previous_api
        .as_deref()
        .filter(|old_api| *old_api != api_url)
    {
        if let Err(delete_error) = credential_delete(state.credentials.as_ref(), old_api) {
            let restore_new = match new_credential_backup {
                Some(ref old_token) => {
                    credential_set(state.credentials.as_ref(), api_url, old_token)
                }
                None => credential_delete(state.credentials.as_ref(), api_url),
            };
            return match restore_new {
                Ok(()) => Err(format!("Could not remove the previous Flynn server credential. The existing session remains active; the new credential was rolled back. {delete_error}")),
                Err(restore_error) => Err(format!("Could not remove the previous Flynn server credential. The existing session remains active, but rollback of the new keyring entry also failed: {delete_error}; {restore_error}")),
            };
        }
    }

    let mut candidate = previous.clone();
    candidate.api_base_url = api_url.to_string();
    bind_authenticated_user(&mut candidate, api_url, user_id);
    *state.config.lock().await = candidate;
    if let Err(save_error) = state.save().await {
        *state.config.lock().await = previous;
        let mut rollback_errors = Vec::new();
        if let Some(old_api) = previous_api
            .as_deref()
            .filter(|old_api| *old_api != api_url)
        {
            if let Some(Some(old_token)) = old_credential_backup {
                if let Err(error) = credential_set(state.credentials.as_ref(), old_api, &old_token)
                {
                    rollback_errors.push(format!(
                        "could not restore the previous server credential: {error}"
                    ));
                }
            }
        }
        let restore_new = match new_credential_backup {
            Some(old_token) => credential_set(state.credentials.as_ref(), api_url, &old_token),
            None => credential_delete(state.credentials.as_ref(), api_url),
        };
        if let Err(error) = restore_new {
            rollback_errors.push(format!(
                "could not restore the new server credential: {error}"
            ));
        }
        if rollback_errors.is_empty() {
            return Err(format!(
                "Could not save the Flynn session; the previous session was restored. {save_error}"
            ));
        }
        return Err(format!("Could not save the Flynn session: {save_error}. Session credential rollback was incomplete: {}", rollback_errors.join("; ")));
    }
    Ok(())
}

fn session_key(api_url: &str, user_id: &str, token: &str) -> String {
    let mut digest = Sha256::new();
    digest.update(api_url.as_bytes());
    digest.update([0]);
    digest.update(user_id.as_bytes());
    digest.update([0]);
    digest.update(token.as_bytes());
    digest
        .finalize()
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

fn session_identity(generation: u64, api_url: &str, user_id: &str, token: &str) -> SessionIdentity {
    SessionIdentity {
        generation,
        api_url: api_url.to_string(),
        user_id: user_id.to_string(),
        token: token.to_string(),
        key: session_key(api_url, user_id, token),
    }
}

trait CredentialStore: Send + Sync {
    fn get(&self, api_url: &str) -> Result<Option<String>, String>;
    fn set(&self, api_url: &str, token: &str) -> Result<(), String>;
    fn delete(&self, api_url: &str) -> Result<(), String>;
}

struct NativeCredentialStore;

impl NativeCredentialStore {
    fn entry(api_url: &str) -> Result<Entry, String> {
        let api_url = api_base(api_url)?;
        let key = Sha256::digest(api_url.as_bytes());
        let key = key
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect::<String>();
        Entry::new(KEYRING_SERVICE, &format!("{KEYRING_USER}-{key}"))
            .map_err(|error| format!("OS credential store is unavailable: {error}"))
    }
}

impl CredentialStore for NativeCredentialStore {
    fn get(&self, api_url: &str) -> Result<Option<String>, String> {
        match Self::entry(api_url)?.get_password() {
            Ok(token) => Ok(Some(token)),
            Err(KeyringError::NoEntry) => Ok(None),
            Err(error) => Err(format!("Could not read the OS credential store: {error}")),
        }
    }

    fn set(&self, api_url: &str, token: &str) -> Result<(), String> {
        Self::entry(api_url)?.set_password(token).map_err(|error| format!("Could not securely save the login. Check that the OS credential store is available: {error}"))
    }

    fn delete(&self, api_url: &str) -> Result<(), String> {
        match Self::entry(api_url)?.delete_credential() {
            Ok(()) | Err(KeyringError::NoEntry) => Ok(()),
            Err(error) => Err(format!("Could not remove the OS credential: {error}")),
        }
    }
}

fn bind_authenticated_user(config: &mut Config, api_url: &str, user_id: &str) {
    let identity_changed = config.credential_api_url.as_deref() != Some(api_url)
        || config.authenticated_user_id.as_deref() != Some(user_id);
    if identity_changed {
        config.successful_hashes.clear();
    }
    config.credential_api_url = Some(api_url.to_string());
    config.authenticated_user_id = Some(user_id.to_string());
    config.credential_cleanup_warning = None;
}

fn user_id_key(id: &Value) -> Result<String, String> {
    match id {
        Value::String(value) if !value.is_empty() => Ok(value.clone()),
        Value::Number(value) => Ok(value.to_string()),
        _ => Err("Flynn returned an account without a valid user ID.".into()),
    }
}

fn sync_hash_key(api_url: &str, user_id: &str, file: &Path) -> String {
    let origin = Url::parse(api_url)
        .map(|url| url.origin().ascii_serialization())
        .unwrap_or_else(|_| api_url.to_string());
    let source = fs::canonicalize(file).unwrap_or_else(|_| file.to_path_buf());
    format!("{origin}\n{user_id}\n{}", source.to_string_lossy())
}

fn api_base(value: &str) -> Result<String, String> {
    let url = Url::parse(value.trim()).map_err(|_| {
        "Enter a valid API base URL, such as https://flynn.example.com.".to_string()
    })?;
    if !matches!(url.scheme(), "http" | "https")
        || url.host_str().is_none()
        || !url.username().is_empty()
        || url.password().is_some()
        || url.query().is_some()
        || url.fragment().is_some()
    {
        return Err(
            "The API base must be an HTTP(S) URL without credentials, query, or fragment.".into(),
        );
    }
    if url.scheme() == "http" && !matches!(url.host_str(), Some("localhost" | "127.0.0.1" | "::1"))
    {
        return Err("Use HTTPS for Flynn API servers. Unencrypted HTTP is allowed only for a local development server.".into());
    }
    let path = url.path().trim_end_matches('/').to_string();
    let mut normalized = url;
    normalized.set_path(&path);
    Ok(normalized.as_str().trim_end_matches('/').to_string())
}

fn endpoint(base: &str, path: &str) -> Result<String, String> {
    Ok(format!("{}/api/v1{}", api_base(base)?, path))
}

fn retry_delay(retry_after: Option<&str>, attempt: u32) -> Duration {
    let fallback = Duration::from_secs(1_u64.checked_shl(attempt.min(5)).unwrap_or(32));
    let Some(value) = retry_after else {
        return fallback.min(Duration::from_secs(30));
    };
    if let Ok(seconds) = value.trim().parse::<u64>() {
        return Duration::from_secs(seconds.min(300));
    }
    if let Ok(date) = httpdate::parse_http_date(value) {
        return date
            .duration_since(std::time::SystemTime::now())
            .unwrap_or_default()
            .min(Duration::from_secs(300));
    }
    fallback.min(Duration::from_secs(30))
}

fn is_retryable_status(status: StatusCode) -> bool {
    matches!(status.as_u16(), 429 | 500 | 502 | 503 | 504)
}

async fn get_with_retry_fenced(
    state: &AppState,
    url: &str,
    session: &SessionIdentity,
) -> Result<reqwest::Response, String> {
    let mut last_error = String::new();
    let mut cancelled = state.session_events.subscribe();
    if !state.session_is_current(session) {
        return Err("SESSION_CHANGED: Flynn session changed before upload was sent.".into());
    }
    for attempt in 0..5 {
        if !state.session_is_current(session) {
            return Err("SESSION_CHANGED: Flynn session changed; operation cancelled.".into());
        }
        let response = send_fenced(
            state,
            state.client.get(url).bearer_auth(&session.token),
            session,
            &mut cancelled,
        )
        .await;
        match response {
            Ok(response) if is_retryable_status(response.status()) && attempt < 4 => {
                let delay = retry_delay(
                    response
                        .headers()
                        .get(reqwest::header::RETRY_AFTER)
                        .and_then(|v| v.to_str().ok()),
                    attempt,
                );
                drop(response);
                tokio::select! {
                    biased;
                    _ = cancelled.changed() => return Err("SESSION_CHANGED: Flynn session changed; operation cancelled.".into()),
                    _ = tokio::time::sleep(delay) => {}
                }
            }
            Ok(response) => return Ok(response),
            Err(error) if error.starts_with("SESSION_CHANGED:") => return Err(error),
            Err(error) => {
                last_error = error;
                if attempt < 4 {
                    tokio::select! {
                        biased;
                        _ = cancelled.changed() => return Err("SESSION_CHANGED: Flynn session changed; operation cancelled.".into()),
                        _ = tokio::time::sleep(retry_delay(None, attempt)) => {}
                    }
                }
            }
        }
    }
    Err(format!("Network error after bounded retries: {last_error}"))
}

async fn post_upload_fenced(
    state: &AppState,
    url: &str,
    session: &SessionIdentity,
    body: Vec<u8>,
) -> Result<reqwest::Response, String> {
    if !state.session_is_current(session) {
        return Err("SESSION_CHANGED: Flynn session changed before upload was sent.".into());
    }
    let mut cancelled = state.session_events.subscribe();
    for attempt in 0..3 {
        let part = multipart::Part::bytes(body.clone())
            .file_name("Flynn.lua")
            .mime_str("application/octet-stream")
            .map_err(|_| "Could not prepare Flynn.lua upload.".to_string())?;
        let response = send_fenced(
            state,
            state
                .client
                .post(url)
                .bearer_auth(&session.token)
                .multipart(multipart::Form::new().part("file", part)),
            session,
            &mut cancelled,
        )
        .await
        .map_err(|error| {
            if error.starts_with("SESSION_CHANGED:") {
                error
            } else {
                format!("Network error uploading Flynn.lua: {error}")
            }
        })?;
        if response.status() != StatusCode::TOO_MANY_REQUESTS || attempt == 2 {
            return Ok(response);
        }
        let delay = retry_delay(
            response
                .headers()
                .get(reqwest::header::RETRY_AFTER)
                .and_then(|value| value.to_str().ok()),
            attempt,
        );
        drop(response);
        tokio::select! {
            biased;
            _ = cancelled.changed() => return Err("SESSION_CHANGED: Flynn session changed during rate-limit backoff.".into()),
            _ = tokio::time::sleep(delay) => {}
        }
    }
    unreachable!("bounded upload retry loop always returns")
}

async fn mark_attempt_ambiguous(
    state: &AppState,
    attempt: &SubmissionAttempt,
    session: &SessionIdentity,
) -> bool {
    let _mutation = state.session_mutation.lock().await;
    if !state.session_identity_is_current(session).await {
        return false;
    }
    let mut config = state.config.lock().await;
    if let Some(saved) = config
        .submission_attempts
        .iter_mut()
        .find(|saved| same_attempt(saved, attempt))
    {
        saved.state = "ambiguous".into();
    }
    drop(config);
    let _ = state.save().await;
    true
}

async fn mark_attempt_failed(
    state: &AppState,
    attempt: &SubmissionAttempt,
    session: &SessionIdentity,
) -> bool {
    let _mutation = state.session_mutation.lock().await;
    if !state.session_identity_is_current(session).await {
        return false;
    }
    let mut config = state.config.lock().await;
    if let Some(saved) = config
        .submission_attempts
        .iter_mut()
        .find(|saved| same_attempt(saved, attempt))
    {
        saved.state = "failed".into();
    }
    drop(config);
    let _ = state.save().await;
    true
}

async fn remove_submission_attempt(
    state: &AppState,
    attempt: &SubmissionAttempt,
    session: &SessionIdentity,
) -> bool {
    let _mutation = state.session_mutation.lock().await;
    if !state.session_identity_is_current(session).await {
        return false;
    }
    state
        .config
        .lock()
        .await
        .submission_attempts
        .retain(|saved| !same_attempt(saved, attempt));
    let _ = state.save().await;
    true
}

fn same_attempt(left: &SubmissionAttempt, right: &SubmissionAttempt) -> bool {
    left.file == right.file
        && left.hash == right.hash
        && left.api_base_url == right.api_base_url
        && left.user_id == right.user_id
        && left.session_key == right.session_key
}

fn same_submission(left: &SubmissionAttempt, right: &SubmissionAttempt) -> bool {
    left.file == right.file
        && left.hash == right.hash
        && left.api_base_url == right.api_base_url
        && left.user_id == right.user_id
}

fn failed_source_hash(root: &Path, path: &Path) -> Option<String> {
    if let Ok((_, hash)) = read_file_with_hash_under_root(root, path) {
        return Some(hash);
    }
    // Files over the upload limit cannot be read into memory to hash. Use a
    // stable metadata fingerprint so unchanged invalid content is not queued
    // on every reconciliation pass; a changed size/mtime can be retried.
    let metadata = fs::symlink_metadata(path).ok()?;
    if !metadata.is_file() || metadata.file_type().is_symlink() {
        return None;
    }
    let modified = metadata
        .modified()
        .ok()?
        .duration_since(std::time::UNIX_EPOCH)
        .ok()?
        .as_nanos();
    let fingerprint = format!("invalid:{}:{modified}", metadata.len());
    let digest = Sha256::digest(fingerprint.as_bytes());
    Some(digest.iter().map(|byte| format!("{byte:02x}")).collect())
}

async fn persist_prepost_failure(
    state: &AppState,
    path: &Path,
    root: &Path,
    session: &SessionIdentity,
) -> Result<(), String> {
    let Some(hash) = failed_source_hash(root, path) else {
        return Ok(());
    };
    let attempt = SubmissionAttempt {
        file: fs::canonicalize(path)
            .unwrap_or_else(|_| path.to_path_buf())
            .to_string_lossy()
            .into_owned(),
        hash,
        api_base_url: session.api_url.clone(),
        user_id: session.user_id.clone(),
        session_key: session.key.clone(),
        state: "failed".into(),
    };
    let _mutation = state.session_mutation.lock().await;
    if !state.session_identity_is_current(session).await {
        return Err(
            "SESSION_CHANGED: Flynn session changed before saving the blocked upload.".into(),
        );
    }
    {
        let mut config = state.config.lock().await;
        config
            .submission_attempts
            .retain(|saved| !same_submission(saved, &attempt));
        config.submission_attempts.push(attempt);
    }
    state.save().await.map_err(|error| {
        format!("Could not persist the blocked upload attempt; automatic retry may occur. {error}")
    })
}

fn blocking_attempt_state<'a>(
    attempts: &'a [SubmissionAttempt],
    file: &str,
    hash: &str,
    api_url: &str,
    user_id: &str,
) -> Option<&'a str> {
    attempts
        .iter()
        .find(|saved| {
            saved.file == file
                && saved.hash == hash
                && saved.api_base_url == api_url
                && saved.user_id == user_id
                && matches!(saved.state.as_str(), "ambiguous" | "submitting" | "failed")
        })
        .map(|attempt| attempt.state.as_str())
}

fn upload_blocked_by_attempt(
    attempts: &[SubmissionAttempt],
    file: &str,
    hash: &str,
    api_url: &str,
    user_id: &str,
    allow_ambiguous_retry: bool,
    allow_failed_retry: bool,
) -> bool {
    match blocking_attempt_state(attempts, file, hash, api_url, user_id) {
        Some("failed") => !allow_failed_retry,
        Some("ambiguous" | "submitting") => !allow_ambiguous_retry,
        _ => false,
    }
}

#[allow(clippy::too_many_arguments)]
fn should_reconcile_file(
    include_pending: bool,
    pending: bool,
    changed: bool,
    attempts: &[SubmissionAttempt],
    file: &str,
    hash: &str,
    api_url: &str,
    user_id: &str,
) -> bool {
    (include_pending && pending)
        || (!pending
            && changed
            && blocking_attempt_state(attempts, file, hash, api_url, user_id).is_none())
}

fn visible_submission_attempts(
    config: &Config,
    api_url: &str,
    user_id: Option<&str>,
    files: &[DumpFile],
    root: Option<&Path>,
) -> Vec<VisibleSubmissionAttempt> {
    config
        .submission_attempts
        .iter()
        .filter(|attempt| {
            attempt.api_base_url == api_url
                && user_id == Some(attempt.user_id.as_str())
                && matches!(attempt.state.as_str(), "ambiguous" | "submitting" | "failed")
                && files.iter().any(|file| {
                    file.enabled
                        && file.path == attempt.file
                        && root
                            .and_then(|root| read_file_with_hash_under_root(root, Path::new(&file.path)).ok())
                            .is_some_and(|(_, current_hash)| current_hash == attempt.hash)
                })
        })
        .map(|attempt| VisibleSubmissionAttempt {
            file: attempt.file.clone(),
            state: if attempt.state == "submitting" {
                "ambiguous".into()
            } else {
                attempt.state.clone()
            },
            message: if attempt.state == "failed" {
                "This exact file content previously failed before or during ingestion. Automatic retry is paused; use Upload Now to retry, or let changed content sync automatically.".into()
            } else {
                "Flynn may have accepted this upload before the app stopped. Automatic retry is blocked to avoid a duplicate.".into()
            },
        })
        .collect()
}

fn random_secret(len: usize) -> String {
    rand::thread_rng()
        .sample_iter(&Alphanumeric)
        .take(len)
        .map(char::from)
        .collect()
}

fn callback_state_matches(callback_state: Option<&str>, expected_state: &str) -> bool {
    let Some(callback_state) = callback_state else {
        return false;
    };
    if callback_state.len() != expected_state.len() {
        return false;
    }
    callback_state
        .bytes()
        .zip(expected_state.bytes())
        .fold(0_u8, |difference, (left, right)| {
            difference | (left ^ right)
        })
        == 0
}

fn pkce_challenge(verifier: &str) -> String {
    URL_SAFE_NO_PAD.encode(Sha256::digest(verifier.as_bytes()))
}

#[tauri::command]
async fn get_status(state: tauri::State<'_, AppState>) -> Result<DesktopStatus, String> {
    get_status_for_state(&state).await
}

async fn get_status_for_state(state: &AppState) -> Result<DesktopStatus, String> {
    let generation = state.session_generation.load(Ordering::SeqCst);
    let (
        api_url,
        credential_api_url,
        authenticated_user_id,
        root,
        auto_sync,
        minimize_to_tray,
        launch_at_login,
        activity,
    ) = {
        let config = state.config.lock().await;
        (
            config.api_base_url.clone(),
            config.credential_api_url.clone(),
            config.authenticated_user_id.clone(),
            config.wow_root.clone(),
            config.auto_sync,
            config.minimize_to_tray,
            config.launch_at_login,
            config.activity.clone(),
        )
    };
    let token = if credential_matches_api(credential_api_url.as_deref(), &api_url) {
        credential_get(state.credentials.as_ref(), &api_url)?
    } else {
        None
    };
    let no_token_at_start = token.is_none();
    let mut status_session = None;
    let mut signed_out_after_unauthorized = None;
    let account = if let Some(token) = token.as_deref() {
        let probe = SessionIdentity {
            generation,
            api_url: api_url.clone(),
            user_id: authenticated_user_id.clone().unwrap_or_default(),
            token: token.to_string(),
            key: String::new(),
        };
        let response = get_with_retry_fenced(state, &endpoint(&api_url, "/me")?, &probe).await;
        if !state.session_identity_is_current(&probe).await {
            return Err(
                "Flynn session changed while status was being checked. Refresh and retry.".into(),
            );
        }
        match response {
            Ok(response) if response.status() == StatusCode::UNAUTHORIZED => {
                match clear_active_token_generation(state, &api_url, token, generation).await {
                    Ok(Some(cleared_generation)) => {
                        signed_out_after_unauthorized = Some(cleared_generation);
                        None
                    }
                    Err(error) => {
                        // A keyring delete failure happens only after the
                        // signed-out config commits. Return that status with its
                        // persisted cleanup warning; a save failure restores
                        // the old binding and remains an error.
                        let config = state.config.lock().await;
                        if config.credential_api_url.is_none()
                            && config.authenticated_user_id.is_none()
                        {
                            signed_out_after_unauthorized =
                                Some(state.session_generation.load(Ordering::SeqCst));
                            None
                        } else {
                            return Err(error);
                        }
                    }
                    Ok(None) => {
                        return Err(
                            "Flynn session changed while status was being checked. Refresh and retry."
                                .into(),
                        );
                    }
                }
            }
            Ok(response) if response.status().is_success() => {
                let account = parse_data_fenced::<Account>(state, response, &probe).await?;
                if !state.session_identity_is_current(&probe).await {
                    return Err(
                        "Flynn session changed while status was being checked. Refresh and retry."
                            .into(),
                    );
                }
                let _mutation = state.session_mutation.lock().await;
                if !state.session_identity_is_current(&probe).await {
                    return Err(
                        "Flynn session changed while status was being checked. Refresh and retry."
                            .into(),
                    );
                }
                let user_id = user_id_key(&account.id)?;
                {
                    let mut config = state.config.lock().await;
                    if config
                        .authenticated_user_id
                        .as_deref()
                        .is_some_and(|old| old != user_id)
                    {
                        state.invalidate_session();
                    }
                    bind_authenticated_user(&mut config, &api_url, &user_id);
                }
                state.save().await?;
                status_session = Some(probe.clone());
                Some(account)
            }
            Ok(response) => {
                let status = response.status();
                let value = match response_json_fenced(state, response, &probe).await {
                    Ok(value) => value,
                    Err(error) if error.starts_with("SESSION_CHANGED:") => {
                        return Err("Flynn session changed while status was being checked. Refresh and retry.".into());
                    }
                    Err(_) => Value::Null,
                };
                if !state.session_identity_is_current(&probe).await {
                    return Err(
                        "Flynn session changed while status was being checked. Refresh and retry."
                            .into(),
                    );
                }
                return Err(api_error_message(&value, status, token));
            }
            Err(error) => return Err(format!("Could not verify your Flynn session: {error}")),
        }
    } else {
        None
    };
    let installations = discovery::detect_installations()
        .into_iter()
        .map(|path| path.to_string_lossy().into_owned())
        .collect();
    let files = state.files().await;
    if let Some(session) = status_session.as_ref() {
        if !state.session_identity_is_current(session).await {
            return Err(
                "Flynn session changed while status was being checked. Refresh and retry.".into(),
            );
        }
    }
    let last_upload_at = activity
        .iter()
        .find(|item| item.status == "completed" && !item.partial)
        .map(|item| item.at.clone());
    let visible_attempts = {
        let config = state.config.lock().await;
        visible_submission_attempts(
            &config,
            &api_url,
            authenticated_user_id.as_deref(),
            &files,
            root.as_deref(),
        )
    };
    let (durability_warning, credential_cleanup_warning) = {
        let config = state.config.lock().await;
        (
            config.durability_warning.clone(),
            config.credential_cleanup_warning.clone(),
        )
    };
    let status = DesktopStatus {
        authenticated: account.is_some(),
        account,
        api_base_url: api_url.clone(),
        wow_root: root.map(|path| path.to_string_lossy().into_owned()),
        auto_sync,
        minimize_to_tray,
        launch_at_login,
        detected_installations: installations,
        files,
        activity,
        submission_attempts: visible_attempts,
        last_upload_at,
        durability_warning,
        credential_cleanup_warning,
    };
    if let Some(cleared_generation) = signed_out_after_unauthorized {
        // Discovery can take long enough for a new login to complete after
        // the 401 cleared the expired token. Hold the session mutation fence
        // through this final check and response construction so this stale
        // signed-out snapshot cannot be returned over that newer session. An
        // orphaned keychain item after a deletion failure does not invalidate
        // the committed signed-out binding.
        let _mutation = state.session_mutation.lock().await;
        if !signed_out_status_is_current(state, cleared_generation, &api_url).await {
            return Err(
                "Flynn session changed while status was being checked. Refresh and retry.".into(),
            );
        }
        return Ok(status);
    }
    if no_token_at_start {
        // A signed-out request can race with a new login. Keep session-changing
        // operations out until the captured generation/config and credential
        // state have been rechecked, or this stale status could overwrite the
        // newly signed-in UI state.
        let _mutation = state.session_mutation.lock().await;
        if !status_snapshot_is_current(
            state,
            generation,
            &api_url,
            credential_api_url.as_deref(),
            authenticated_user_id.as_deref(),
        )
        .await
            || (credential_matches_api(credential_api_url.as_deref(), &api_url)
                && credential_get(state.credentials.as_ref(), &api_url)?.is_some())
        {
            return Err(
                "Flynn session changed while status was being checked. Refresh and retry.".into(),
            );
        }
    }
    Ok(status)
}

async fn status_snapshot_is_current(
    state: &AppState,
    generation: u64,
    api_url: &str,
    credential_api_url: Option<&str>,
    authenticated_user_id: Option<&str>,
) -> bool {
    if state.session_generation.load(Ordering::SeqCst) != generation {
        return false;
    }
    let config_matches = {
        let config = state.config.lock().await;
        config.api_base_url == api_url
            && config.credential_api_url.as_deref() == credential_api_url
            && config.authenticated_user_id.as_deref() == authenticated_user_id
    };
    config_matches && state.session_generation.load(Ordering::SeqCst) == generation
}

/// Caller holds `session_mutation` so a successful check stays valid until its
/// signed-out status response has been returned to Tauri.
async fn signed_out_status_is_current(state: &AppState, generation: u64, api_url: &str) -> bool {
    if !status_snapshot_is_current(state, generation, api_url, None, None).await {
        return false;
    }
    // The local binding is authoritative. A failed keychain deletion can leave
    // an orphaned item behind, but it is not selected for use once the signed-
    // out binding has committed. Do not turn that cleanup failure into a stale
    // status result (or require a second poll to show signed-out state).
    true
}

async fn response_json_fenced(
    state: &AppState,
    response: reqwest::Response,
    session: &SessionIdentity,
) -> Result<Value, String> {
    if !state.session_is_current(session) {
        return Err("SESSION_CHANGED: Flynn session changed while reading a response.".into());
    }
    let mut cancelled = state.session_events.subscribe();
    if !state.session_is_current(session) {
        return Err("SESSION_CHANGED: Flynn session changed while reading a response.".into());
    }
    let status = response.status();
    let value = tokio::select! {
        biased;
        _ = cancelled.changed() => return Err("SESSION_CHANGED: Flynn session changed while reading a response.".into()),
        result = response.json::<Value>() => result
            .map_err(|_| format!("Flynn API returned an unreadable response ({status})."))?,
    };
    if !state.session_is_current(session) {
        return Err("SESSION_CHANGED: Flynn session changed while reading a response.".into());
    }
    Ok(value)
}

async fn parse_data_fenced<T: for<'de> Deserialize<'de>>(
    state: &AppState,
    response: reqwest::Response,
    session: &SessionIdentity,
) -> Result<T, String> {
    let status = response.status();
    let value = response_json_fenced(state, response, session).await?;
    if !status.is_success() {
        return Err(error_message(&value, status));
    }
    serde_json::from_value(value.get("data").cloned().unwrap_or(value))
        .map_err(|_| "Flynn API returned an unexpected response.".into())
}

fn error_message(value: &Value, status: StatusCode) -> String {
    value
        .pointer("/error/message")
        .and_then(Value::as_str)
        .map(str::to_string)
        .unwrap_or_else(|| format!("Flynn API request failed ({status})."))
}

fn api_error_message(value: &Value, status: StatusCode, token: &str) -> String {
    error_message(value, status).replace(token, "[redacted]")
}

#[tauri::command]
async fn connect(
    api_url: String,
    app: AppHandle,
    state: tauri::State<'_, AppState>,
) -> Result<Account, String> {
    let account = connect_with_browser(api_url, &state, |url| {
        use tauri_plugin_opener::OpenerExt;
        app.opener()
            .open_url(url, None::<&str>)
            .map_err(|error| format!("Could not open your browser: {error}"))
    })
    .await?;
    let app_handle = app.clone();
    tauri::async_runtime::spawn(async move {
        let state = app_handle.state::<AppState>();
        resume_pending_dumps(&app_handle, &state).await;
    });
    Ok(account)
}

async fn connect_with_browser<F>(
    api_url: String,
    state: &AppState,
    open_browser: F,
) -> Result<Account, String>
where
    F: FnOnce(&str) -> Result<(), String>,
{
    let api_url = api_base(&api_url)?;
    let connect_generation = state.session_generation.load(Ordering::SeqCst);
    let mut connect_cancelled = state.session_events.subscribe();
    let mut probe = SessionIdentity {
        generation: connect_generation,
        api_url: api_url.clone(),
        user_id: String::new(),
        token: String::new(),
        key: String::new(),
    };
    let verifier = random_secret(64);
    let challenge = pkce_challenge(&verifier);
    let oauth_state = random_secret(48);
    let listener = TcpListener::bind("127.0.0.1:51337").await
        .map_err(|error| format!("Could not open the local sign-in callback on 127.0.0.1:51337: {error}. Close another Flynn Desktop instance and retry."))?;
    let mut authorize = Url::parse(&endpoint(&api_url, "/auth/desktop/authorize")?)
        .map_err(|error| error.to_string())?;
    authorize
        .query_pairs_mut()
        .append_pair("codeChallenge", &challenge)
        .append_pair("state", &oauth_state)
        .append_pair("redirectUri", CALLBACK_URI);
    open_browser(authorize.as_str())?;

    if !state.session_is_current(&probe) {
        return Err("SESSION_CHANGED: Flynn session changed during sign-in.".into());
    }
    let callback = tokio::select! {
        biased;
        _ = connect_cancelled.changed() => return Err("SESSION_CHANGED: Flynn session changed during sign-in.".into()),
        result = timeout(Duration::from_secs(300), wait_for_callback(listener, &oauth_state)) => result
            .map_err(|_| "Sign-in timed out. Return to Flynn Desktop and try again.".to_string())??,
    };
    let (code, mut response_socket) = callback;
    let result = finish_browser_connect(
        state,
        &api_url,
        code,
        &verifier,
        &mut probe,
        &mut connect_cancelled,
    )
    .await;
    let html = connect_callback_page(result.is_ok());
    let _ = response_socket.write_all(format!("HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}", html.len(), html).as_bytes()).await;
    result
}

fn connect_callback_page(success: bool) -> &'static str {
    if success {
        "<!doctype html><meta charset=utf-8><title>Flynn Desktop</title><p>Flynn Desktop connected successfully. You can return to the app.</p>"
    } else {
        "<!doctype html><meta charset=utf-8><title>Flynn Desktop</title><p>Flynn could not finish connecting. Return to Flynn Desktop to see the error and try again.</p>"
    }
}

async fn finish_browser_connect(
    state: &AppState,
    api_url: &str,
    code: String,
    verifier: &str,
    probe: &mut SessionIdentity,
    connect_cancelled: &mut watch::Receiver<u64>,
) -> Result<Account, String> {
    let response = send_fenced(
        state,
        state
            .client
            .post(endpoint(api_url, "/auth/desktop/token")?)
            .json(&json!({"code": code, "codeVerifier": verifier})),
        probe,
        connect_cancelled,
    )
    .await
    .map_err(|error| format!("Could not exchange sign-in code with Flynn: {error}"))?;
    let payload = response_json_fenced(state, response, probe).await?;
    if !state.session_is_current(probe) {
        return Err("SESSION_CHANGED: Flynn session changed during sign-in.".into());
    }
    let token = payload
        .pointer("/data/accessToken")
        .and_then(Value::as_str)
        .ok_or_else(|| {
            payload
                .pointer("/error/message")
                .and_then(Value::as_str)
                .unwrap_or("Flynn did not issue a desktop credential.")
                .to_string()
        })?
        .to_string();
    probe.token = token.clone();
    let response = get_with_retry_fenced(state, &endpoint(api_url, "/me")?, probe)
        .await
        .map_err(|error| format!("Signed in, but could not verify the Flynn account: {error}"))?;
    if !state.session_is_current(probe) {
        return Err("SESSION_CHANGED: Flynn session changed during sign-in.".into());
    }
    let account = parse_data_fenced::<Account>(state, response, probe).await?;
    if !state.session_is_current(probe) {
        return Err("SESSION_CHANGED: Flynn session changed during sign-in.".into());
    }
    let user_id = user_id_key(&account.id)?;
    // Credential storage is deliberately fail-closed. Never retain the token in app settings.
    {
        let _mutation = state.session_mutation.lock().await;
        if !state.session_is_current(probe) {
            return Err("SESSION_CHANGED: Flynn session changed during sign-in.".into());
        }
        install_authenticated_session_locked(state, api_url, &token, &user_id).await?;
    }
    Ok(account)
}

#[cfg(unix)]
fn sync_parent_directory(parent: &Path) -> std::io::Result<()> {
    fs::File::open(parent)?.sync_all()
}

#[cfg(not(unix))]
fn sync_parent_directory(_parent: &Path) -> std::io::Result<()> {
    Ok(())
}

async fn wait_for_callback(
    listener: TcpListener,
    expected_state: &str,
) -> Result<(String, tokio::net::TcpStream), String> {
    loop {
        let (mut socket, peer) = listener.accept().await.map_err(|error| error.to_string())?;
        if !peer.ip().is_loopback() {
            continue;
        }
        let mut buffer = [0_u8; MAX_CALLBACK_BYTES];
        let mut count = 0;
        loop {
            if count == buffer.len() {
                return Err("Sign-in callback request was too large.".into());
            }
            let read = timeout(CALLBACK_READ_TIMEOUT, socket.read(&mut buffer[count..]))
                .await
                .map_err(|_| "Sign-in callback request timed out.".to_string())?
                .map_err(|error| error.to_string())?;
            if read == 0 {
                return Err("Sign-in callback closed before sending a request.".into());
            }
            count += read;
            if buffer[..count]
                .windows(4)
                .any(|window| window == b"\r\n\r\n")
            {
                break;
            }
        }
        let request = String::from_utf8_lossy(&buffer[..count]);
        let target = request
            .lines()
            .next()
            .and_then(|line| line.split_whitespace().nth(1))
            .ok_or("Invalid callback request.")?;
        let callback = Url::parse(&format!("http://127.0.0.1:51337{target}"))
            .map_err(|_| "Invalid callback URL.")?;
        if callback.path() != "/oauth/callback" {
            continue;
        }
        let params: HashMap<String, String> = callback
            .query_pairs()
            .map(|(key, value)| (key.into_owned(), value.into_owned()))
            .collect();
        if !callback_state_matches(params.get("state").map(String::as_str), expected_state) {
            let body = "Invalid Flynn sign-in state. Return to Flynn Desktop and retry.";
            let _ = socket.write_all(format!("HTTP/1.1 400 Bad Request\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}", body.len(), body).as_bytes()).await;
            continue;
        }
        if let Some(error) = params.get("error") {
            return Err(format!("Flynn authorization was not completed ({error})."));
        }
        if let Some(code) = params.get("code") {
            return Ok((code.clone(), socket));
        }
        return Err("Flynn authorization callback did not contain a code.".into());
    }
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
struct AppSettings {
    api_base_url: Option<String>,
    wow_root: Option<String>,
    auto_sync: Option<bool>,
    minimize_to_tray: Option<bool>,
}

fn settings_candidate(current: &Config, settings: AppSettings) -> Result<Config, String> {
    let mut candidate = current.clone();
    if let Some(api) = settings.api_base_url {
        let api = api_base(&api)?;
        if api != candidate.api_base_url {
            candidate.api_base_url = api;
            candidate.credential_api_url = None;
            candidate.authenticated_user_id = None;
            candidate.successful_hashes.clear();
        }
    }
    if let Some(root) = settings.wow_root {
        let path = PathBuf::from(root);
        if !path.is_dir() {
            return Err("Select an existing World of Warcraft installation directory.".into());
        }
        let canonical = fs::canonicalize(&path)
            .map_err(|error| format!("Could not resolve the selected WoW folder: {error}"))?;
        if !discovery::is_wow_root(&canonical) {
            return Err(
                "Select a World of Warcraft folder containing a supported client flavor directory."
                    .into(),
            );
        }
        candidate.wow_root = Some(canonical);
    }
    if let Some(value) = settings.auto_sync {
        candidate.auto_sync = value;
    }
    if let Some(value) = settings.minimize_to_tray {
        candidate.minimize_to_tray = value;
    }
    Ok(candidate)
}

async fn commit_settings_candidate(state: &AppState, candidate: Config) -> Result<(), String> {
    let previous = state.config.lock().await.clone();
    let credential_to_remove = (candidate.api_base_url != previous.api_base_url)
        .then(|| previous.credential_api_url.clone())
        .flatten();
    let credential_backup = credential_to_remove
        .as_deref()
        .map(|api_url| credential_get(state.credentials.as_ref(), api_url))
        .transpose()?;
    if let Some(api_url) = credential_to_remove.as_deref() {
        credential_delete(state.credentials.as_ref(), api_url)?;
    }
    *state.config.lock().await = candidate.clone();
    if let Err(error) = state.save().await {
        *state.config.lock().await = previous.clone();
        let mut restore_error = None;
        if let (Some(api_url), Some(Some(token))) =
            (credential_to_remove.as_deref(), credential_backup.as_ref())
        {
            if let Err(error) = credential_set(state.credentials.as_ref(), api_url, token) {
                restore_error = Some(format!(
                    "The prior credential could not be restored: {error}"
                ));
            }
        }
        if let Some(restore_error) = restore_error {
            return Err(format!("{error} {restore_error}"));
        }
        return Err(error);
    }
    if candidate.api_base_url != previous.api_base_url {
        state.invalidate_session();
    }
    Ok(())
}

#[tauri::command]
async fn update_settings(
    settings: AppSettings,
    app: AppHandle,
    state: tauri::State<'_, AppState>,
) -> Result<DesktopStatus, String> {
    let mutation = state.session_mutation.lock().await;
    let (_candidate, candidate_watcher) = update_settings_transaction_locked(
        &state,
        settings,
        |candidate| {
            // Construct the replacement before changing either the durable settings
            // or credential store. A watcher setup error leaves settings untouched.
            build_watcher(
                state.file_events.clone(),
                candidate.auto_sync,
                candidate.wow_root.as_deref(),
            )
        },
        || async {},
    )
    .await?;
    drop(
        state
            .watcher
            .lock()
            .map_err(|_| "Filesystem watcher lock was poisoned.".to_string())?,
    );
    *state
        .watcher
        .lock()
        .unwrap_or_else(std::sync::PoisonError::into_inner) = candidate_watcher;
    reconcile_files(&state, true).await;
    let _ = app.emit("files-changed", state.files().await);
    drop(mutation);
    get_status(state).await
}

#[cfg(test)]
async fn update_settings_transaction_with<R, F, Fut>(
    state: &AppState,
    settings: AppSettings,
    prepare: impl FnOnce(&Config) -> Result<R, String>,
    after_snapshot: F,
) -> Result<(Config, R), String>
where
    F: FnOnce() -> Fut,
    Fut: std::future::Future<Output = ()>,
{
    let _mutation = state.session_mutation.lock().await;
    update_settings_transaction_locked(state, settings, prepare, after_snapshot).await
}

async fn update_settings_transaction_locked<R, F, Fut>(
    state: &AppState,
    settings: AppSettings,
    prepare: impl FnOnce(&Config) -> Result<R, String>,
    after_snapshot: F,
) -> Result<(Config, R), String>
where
    F: FnOnce() -> Fut,
    Fut: std::future::Future<Output = ()>,
{
    let previous = state.config.lock().await.clone();
    let candidate = settings_candidate(&previous, settings)?;
    // Keep the entire snapshot-to-commit interval inside the config mutation
    // lock. The test hook lets a test hold that interval open deterministically.
    after_snapshot().await;
    let prepared = prepare(&candidate)?;
    commit_settings_candidate(state, candidate.clone()).await?;
    Ok((candidate, prepared))
}

#[tauri::command]
async fn set_sync_scope(
    flavor: String,
    account: String,
    enabled: bool,
    app: AppHandle,
    state: tauri::State<'_, AppState>,
) -> Result<DesktopStatus, String> {
    if !is_supported_flavor(&flavor) || account.is_empty() || account.len() > 128 {
        return Err("Invalid World of Warcraft account/flavor selection.".into());
    }
    let mutation = state.session_mutation.lock().await;
    set_sync_scope_config_locked(&state, &flavor, &account, enabled).await?;
    refresh_watcher(&app, &state).await?;
    drop(mutation);
    get_status(state).await
}

#[cfg(test)]
async fn set_sync_scope_config(
    state: &AppState,
    flavor: &str,
    account: &str,
    enabled: bool,
) -> Result<(), String> {
    let _mutation = state.session_mutation.lock().await;
    set_sync_scope_config_locked(state, flavor, account, enabled).await
}

fn is_supported_flavor(flavor: &str) -> bool {
    ["retail", "classic_era", "classic", "forever"].contains(&flavor)
}

async fn set_sync_scope_config_locked(
    state: &AppState,
    flavor: &str,
    account: &str,
    enabled: bool,
) -> Result<(), String> {
    if !is_supported_flavor(flavor) {
        return Err("Invalid World of Warcraft account/flavor selection.".into());
    }
    let flavor_dir = format!("_{flavor}_");
    let key = discovery::scope_key(&flavor_dir, account);
    {
        let mut config = state.config.lock().await;
        if enabled {
            config.disabled_scopes.remove(&key);
        } else {
            config.disabled_scopes.insert(key);
        }
    }
    state.save().await
}

#[tauri::command]
async fn set_launch_at_login(
    enabled: bool,
    app: AppHandle,
    state: tauri::State<'_, AppState>,
) -> Result<DesktopStatus, String> {
    use tauri_plugin_autostart::ManagerExt;
    set_launch_at_login_config(&state, enabled, || {
        let result = if enabled {
            app.autolaunch().enable()
        } else {
            app.autolaunch().disable()
        };
        result.map_err(|error| format!("Could not update launch-at-login setting: {error}"))
    })
    .await?;
    get_status(state).await
}

async fn set_launch_at_login_config(
    state: &AppState,
    enabled: bool,
    apply_platform_setting: impl FnOnce() -> Result<(), String>,
) -> Result<(), String> {
    let _mutation = state.session_mutation.lock().await;
    apply_platform_setting()?;
    state.config.lock().await.launch_at_login = enabled;
    state.save().await
}

#[tauri::command]
async fn logout(state: tauri::State<'_, AppState>) -> Result<(), String> {
    logout_with_pre_rename_failure(&state, None).await
}

async fn logout_with_pre_rename_failure(
    state: &AppState,
    pre_rename_failure: Option<String>,
) -> Result<(), String> {
    let _mutation = state.session_mutation.lock().await;
    let previous = state.config.lock().await.clone();
    let credential_api_url = previous
        .credential_api_url
        .clone()
        .unwrap_or_else(|| previous.api_base_url.clone());
    state.invalidate_session();
    let mut candidate = previous.clone();
    candidate.credential_api_url = None;
    candidate.authenticated_user_id = None;
    candidate.successful_hashes.clear();
    candidate.credential_cleanup_warning = None;
    *state.config.lock().await = candidate;

    // The durable signed-out config is the commit point. If it cannot be
    // written before rename, retain the native credential and restore memory.
    let save_result = if let Some(message) = pre_rename_failure {
        state
            .save_with_hooks(sync_parent_directory, move || {
                Err(std::io::Error::other(message))
            })
            .await
    } else {
        state.save().await
    };
    if let Err(error) = save_result {
        *state.config.lock().await = previous;
        return Err(format!("Could not save logout; the OS credential was retained and the previous local session remains configured. {error}"));
    }
    match credential_delete(state.credentials.as_ref(), &credential_api_url) {
        Ok(()) => Ok(()),
        Err(delete_error) => {
            let warning = format!("You are signed out locally, but the credential could not be removed from the OS credential store. It is not bound to an active session and will not be used. {delete_error}");
            state.config.lock().await.credential_cleanup_warning = Some(warning.clone());
            match state.save().await {
                Ok(()) => Err(warning),
                Err(save_error) => {
                    let warning = format!(
                        "{warning} The cleanup warning could not be persisted: {save_error}"
                    );
                    state.config.lock().await.credential_cleanup_warning = Some(warning.clone());
                    Err(warning)
                }
            }
        }
    }
}

#[tauri::command]
async fn upload_now(
    app: AppHandle,
    state: tauri::State<'_, AppState>,
) -> Result<Vec<SyncOutcome>, String> {
    let api_url = state.config.lock().await.api_base_url.clone();
    if active_token(&state, &api_url).await?.is_none() {
        return Err("Connect to Flynn before uploading.".into());
    }
    let files = state
        .files()
        .await
        .into_iter()
        .filter(|file| file.enabled)
        .collect::<Vec<_>>();
    if files.is_empty() {
        return Err("No enabled Flynn.lua files were found. Check the WoW installation directory and sync toggles.".into());
    }
    let mut outcomes = Vec::new();
    for file in files {
        outcomes.push(sync_file(&app, &state, &file.path, false, true).await);
    }
    Ok(outcomes)
}

#[tauri::command]
async fn retry_ambiguous_upload(
    file: String,
    accept_duplicate_risk: bool,
    app: AppHandle,
    state: tauri::State<'_, AppState>,
) -> Result<SyncOutcome, String> {
    retry_ambiguous_upload_inner(file, accept_duplicate_risk, &app, &state).await
}

async fn retry_ambiguous_upload_inner<R: Runtime>(
    file: String,
    accept_duplicate_risk: bool,
    app: &AppHandle<R>,
    state: &AppState,
) -> Result<SyncOutcome, String> {
    if !accept_duplicate_risk {
        return Err("Explicit confirmation is required because the earlier upload may already have been accepted.".into());
    }
    let path = PathBuf::from(&file);
    let current_files = state.files().await;
    if !current_files
        .iter()
        .any(|entry| entry.path == file && entry.enabled)
    {
        return Err(
            "This file is no longer an enabled Flynn.lua source under the selected WoW folder."
                .into(),
        );
    }
    let (active_api, active_user, root) = {
        let config = state.config.lock().await;
        (
            config.api_base_url.clone(),
            config.authenticated_user_id.clone(),
            config.wow_root.clone(),
        )
    };
    let root = root.ok_or_else(|| "Select a WoW folder before uploading Flynn.lua.".to_string())?;
    let (_, current_hash) = read_file_with_hash_under_root(&root, &path)?;
    let has_ambiguous = state
        .config
        .lock()
        .await
        .submission_attempts
        .iter()
        .any(|attempt| {
            attempt.file == file
                && attempt.hash == current_hash
                && attempt.api_base_url == active_api
                && Some(attempt.user_id.as_str()) == active_user.as_deref()
                && matches!(attempt.state.as_str(), "ambiguous" | "submitting")
        });
    if !has_ambiguous {
        return Err(
            "No ambiguous upload attempt exists for this file. Use Upload Now instead.".into(),
        );
    }
    Ok(sync_file(app, state, &file, true, true).await)
}

async fn sync_file<R: Runtime>(
    app: &AppHandle<R>,
    state: &AppState,
    file_name: &str,
    allow_ambiguous_retry: bool,
    allow_failed_retry: bool,
) -> SyncOutcome {
    let path = PathBuf::from(file_name);
    let path_key = path.to_string_lossy().into_owned();
    let (operation_generation, operation_api) = {
        let _mutation = state.session_mutation.lock().await;
        let api = state.config.lock().await.api_base_url.clone();
        (state.session_generation.load(Ordering::SeqCst), api)
    };
    {
        let mut in_flight = state.in_flight.lock().await;
        if !in_flight.insert(path_key.clone()) {
            state.dirty_paths.lock().await.insert(path_key.clone());
            return SyncOutcome {
                path: file_name.into(),
                status: "processing".into(),
                message: "This Flynn.lua file is already syncing.".into(),
                partial: false,
            };
        }
    }
    let result = upload_one(app, state, &path, allow_ambiguous_retry, allow_failed_retry).await;
    let outcome = match result {
        Ok((item, hash, _api_url, _user_id, session)) => {
            if !commit_sync_result(state, &item, &hash, &path, &session).await {
                state.in_flight.lock().await.remove(&path_key);
                return SyncOutcome {
                    path: file_name.into(),
                    status: "cancelled".into(),
                    message: "The Flynn session changed; this operation was cancelled.".into(),
                    partial: false,
                };
            }
            record_activity_fenced(app, state, item.clone(), &session).await;
            SyncOutcome {
                path: file_name.into(),
                status: item.status,
                message: item.message,
                partial: item.partial,
            }
        }
        Err(message) => {
            let status = if message.starts_with("AUTH_REQUIRED:") {
                "reauthentication_required"
            } else if message.starts_with("AMBIGUOUS:") {
                "ambiguous"
            } else if message.starts_with("REJECTED:") {
                "failed"
            } else if message.starts_with("SESSION_CHANGED:") {
                "cancelled"
            } else {
                "failed"
            };
            if status == "cancelled" {
                state.in_flight.lock().await.remove(&path_key);
                return SyncOutcome {
                    path: file_name.into(),
                    status: status.into(),
                    message: "The Flynn session changed; this operation was cancelled.".into(),
                    partial: false,
                };
            }
            let item = ActivityItem {
                id: random_secret(12),
                file: file_name.into(),
                status: status.into(),
                message: message
                    .trim_start_matches("AUTH_REQUIRED:")
                    .trim_start_matches("AMBIGUOUS:")
                    .trim_start_matches("REJECTED:")
                    .trim_start_matches("SESSION_CHANGED:")
                    .to_string(),
                at: now_iso(),
                characters_seen: None,
                characters_updated: None,
                partial: false,
                results: Vec::new(),
            };
            let _ = record_activity_generation_fenced(
                app,
                state,
                item.clone(),
                operation_generation,
                &operation_api,
            )
            .await;
            SyncOutcome {
                path: file_name.into(),
                status: item.status,
                message: item.message,
                partial: item.partial,
            }
        }
    };
    state.in_flight.lock().await.remove(&path_key);
    let was_marked_dirty = state.dirty_paths.lock().await.remove(&path_key);
    if was_marked_dirty {
        let _ = state.file_events.send(path);
    }
    outcome
}

async fn commit_sync_result(
    state: &AppState,
    item: &ActivityItem,
    hash: &str,
    path: &Path,
    session: &SessionIdentity,
) -> bool {
    let _mutation = state.session_mutation.lock().await;
    if !state.session_identity_is_current(session).await {
        return false;
    }
    if matches!(item.status.as_str(), "completed" | "failed") {
        let mut config = state.config.lock().await;
        config.pending_dumps.retain(|dump| {
            !(dump.dump_id == item.id
                && dump.api_base_url == session.api_url
                && dump.user_id == session.user_id)
        });
        if item.status == "completed" && !item.partial {
            config.successful_hashes.insert(
                sync_hash_key(&session.api_url, &session.user_id, path),
                hash.to_string(),
            );
        } else if item.status == "failed" || (item.status == "completed" && item.partial) {
            // A dump can be accepted by POST /dumps yet fail validation during
            // asynchronous ingestion. Remember that terminal outcome as well
            // as synchronous 4xx rejections, so watcher reconciliation does
            // not blindly upload the same invalid bytes again.
            let failed_attempt = SubmissionAttempt {
                file: fs::canonicalize(path)
                    .unwrap_or_else(|_| path.to_path_buf())
                    .to_string_lossy()
                    .into_owned(),
                hash: hash.to_string(),
                api_base_url: session.api_url.clone(),
                user_id: session.user_id.clone(),
                session_key: session.key.clone(),
                state: "failed".into(),
            };
            config
                .submission_attempts
                .retain(|saved| !same_submission(saved, &failed_attempt));
            config.submission_attempts.push(failed_attempt);
        }
        drop(config);
        let _ = state.save().await;
    }
    true
}

async fn upload_one<R: Runtime>(
    app: &AppHandle<R>,
    state: &AppState,
    path: &Path,
    allow_ambiguous_retry: bool,
    allow_failed_retry: bool,
) -> Result<(ActivityItem, String, String, String, SessionIdentity), String> {
    let (base, wow_root) = {
        let config = state.config.lock().await;
        (config.api_base_url.clone(), config.wow_root.clone())
    };
    let session = authenticated_session(state, &base).await?;
    let token = session.token.clone();
    let user_id = session.user_id.clone();
    let canonical_path =
        fs::canonicalize(path).map_err(|error| format!("Could not resolve Flynn.lua: {error}"))?;
    let root =
        wow_root.ok_or_else(|| "Select a WoW folder before uploading Flynn.lua.".to_string())?;
    if let Some(pending) = find_pending_dump(state, &base, &user_id, &canonical_path).await {
        if !state.session_identity_is_current(&session).await {
            return Err("SESSION_CHANGED: Flynn session changed.".into());
        }
        let item = poll_dump(app, state, &pending, &session).await;
        return Ok((item, pending.hash, base, user_id, session));
    }
    if let Err(error) = wait_for_file_stability(path).await {
        persist_prepost_failure(state, path, &root, &session).await?;
        return Err(format!("REJECTED:{error}"));
    }
    if !state.session_identity_is_current(&session).await {
        return Err("SESSION_CHANGED: Flynn session changed while waiting for Flynn.lua.".into());
    }
    if state.config.lock().await.wow_root.as_ref() != Some(&root) {
        persist_prepost_failure(state, path, &root, &session).await?;
        return Err(
            "REJECTED:The selected WoW folder changed before Flynn.lua could be read. Retry the upload.".into(),
        );
    }
    let (body, hash) = match read_file_with_hash_under_root(&root, path) {
        Ok(read) => read,
        Err(error) => {
            persist_prepost_failure(state, path, &root, &session).await?;
            return Err(format!("REJECTED:{error}"));
        }
    };
    if !state.session_identity_is_current(&session).await {
        return Err("SESSION_CHANGED: Flynn session changed before upload was prepared.".into());
    }
    let hash_key = sync_hash_key(&base, &user_id, path);
    let attempt = SubmissionAttempt {
        file: canonical_path.to_string_lossy().into_owned(),
        hash: hash.clone(),
        api_base_url: base.clone(),
        user_id: user_id.clone(),
        session_key: session.key.clone(),
        state: "submitting".into(),
    };
    {
        let _mutation = state.session_mutation.lock().await;
        if !state.session_identity_is_current(&session).await {
            return Err(
                "SESSION_CHANGED: Flynn session changed before upload was prepared.".into(),
            );
        }
        let mut config = state.config.lock().await;
        let blocking_state = blocking_attempt_state(
            &config.submission_attempts,
            &attempt.file,
            &hash,
            &base,
            &user_id,
        );
        if upload_blocked_by_attempt(
            &config.submission_attempts,
            &attempt.file,
            &hash,
            &base,
            &user_id,
            allow_ambiguous_retry,
            allow_failed_retry,
        ) {
            return Err(match blocking_state {
                Some("failed") => "REJECTED:Flynn rejected this file previously. Automatic retry is paused until its contents change or you choose Upload Now.".into(),
                _ => "AMBIGUOUS:Flynn cannot confirm whether the previous upload was accepted. To avoid a possible duplicate, automatic retry is blocked. Use the explicit retry action only if you accept that the dump may be uploaded twice.".into(),
            });
        }
        if should_skip_upload(
            config.successful_hashes.get(&hash_key).map(String::as_str),
            &hash,
        ) {
            return Ok((
                ActivityItem {
                    id: random_secret(12),
                    file: path.to_string_lossy().into_owned(),
                    status: "unchanged".into(),
                    message: "File contents have not changed since its last successful sync."
                        .into(),
                    at: now_iso(),
                    characters_seen: None,
                    characters_updated: None,
                    partial: false,
                    results: Vec::new(),
                },
                hash,
                base,
                user_id,
                session,
            ));
        }
        config
            .submission_attempts
            .retain(|saved| !same_submission(saved, &attempt));
        config.submission_attempts.push(attempt.clone());
    }
    state.save().await?;
    let response = match post_upload_fenced(state, &endpoint(&base, "/dumps")?, &session, body)
        .await
    {
        Ok(response) => response,
        Err(error) => {
            if error.contains("before upload was sent") || error.contains("before request dispatch")
            {
                let _ = remove_submission_attempt(state, &attempt, &session).await;
                return Err(
                    "SESSION_CHANGED: Flynn session changed before the upload was sent.".into(),
                );
            }
            if !mark_attempt_ambiguous(state, &attempt, &session).await {
                return Err(
                    "SESSION_CHANGED: Flynn session changed after the upload request.".into(),
                );
            }
            return Err(format!("AMBIGUOUS:Upload outcome is unknown or was cancelled during a session change: {error}. Flynn will not automatically repost it. Confirm the result on Flynn or explicitly retry, which may create a duplicate."));
        }
    };
    if !state.session_identity_is_current(&session).await {
        return Err("SESSION_CHANGED: Flynn session changed after the upload response.".into());
    }
    if response.status() == StatusCode::UNAUTHORIZED {
        if !remove_submission_attempt(state, &attempt, &session).await {
            return Err("SESSION_CHANGED: Flynn session changed after the upload response.".into());
        }
        match clear_active_token(state, &base, &token, session.generation).await {
            Ok(true) => {}
            Ok(false) => {
                return Err(
                    "SESSION_CHANGED: Flynn session changed after the upload response.".into(),
                )
            }
            Err(error) => return Err(format!("AUTH_REQUIRED:{error}")),
        }
        return Err("AUTH_REQUIRED:Your Flynn login expired. Connect to Flynn again.".into());
    }
    let status = response.status();
    if status.is_server_error() {
        if !mark_attempt_ambiguous(state, &attempt, &session).await {
            return Err("SESSION_CHANGED: Flynn session changed after the upload response.".into());
        }
        return Err(format!("AMBIGUOUS:Flynn returned {status} after the upload request may have been accepted. Automatic repost is blocked to avoid a possible duplicate."));
    }
    let receipt_value: Value = match response_json_fenced(state, response, &session).await {
        Ok(value) => value,
        Err(error) if error.starts_with("SESSION_CHANGED:") => return Err(error),
        Err(_) => {
            if !mark_attempt_ambiguous(state, &attempt, &session).await {
                return Err(
                    "SESSION_CHANGED: Flynn session changed while parsing the upload response."
                        .into(),
                );
            }
            return Err(format!("AMBIGUOUS:Flynn's upload response was unreadable ({status}); the request may have been accepted. Automatic repost is blocked to avoid a possible duplicate."));
        }
    };
    if !state.session_identity_is_current(&session).await {
        return Err(
            "SESSION_CHANGED: Flynn session changed while parsing the upload response.".into(),
        );
    }
    if status != StatusCode::ACCEPTED && !status.is_success() {
        if !mark_attempt_failed(state, &attempt, &session).await {
            return Err(
                "SESSION_CHANGED: Flynn session changed while parsing the upload response.".into(),
            );
        }
        return Err(api_error_message(&receipt_value, status, &token));
    }
    let receipt: UploadReceipt = match serde_json::from_value(
        receipt_value.get("data").cloned().unwrap_or(Value::Null),
    ) {
        Ok(receipt) => receipt,
        Err(_) => {
            if !mark_attempt_ambiguous(state, &attempt, &session).await {
                return Err(
                    "SESSION_CHANGED: Flynn session changed while parsing the upload response."
                        .into(),
                );
            }
            return Err("AMBIGUOUS:Flynn accepted an upload but did not return a dump ID; automatic repost is blocked to avoid a possible duplicate.".into());
        }
    };
    if !valid_upload_receipt(status, &receipt.status) {
        if !mark_attempt_ambiguous(state, &attempt, &session).await {
            return Err(
                "SESSION_CHANGED: Flynn session changed while parsing the upload response.".into(),
            );
        }
        return Err("AMBIGUOUS:Flynn returned an invalid asynchronous upload state; automatic repost is blocked to avoid a possible duplicate.".into());
    }
    let pending = PendingDump {
        dump_id: receipt.dump_id.clone(),
        file: canonical_path.to_string_lossy().into_owned(),
        hash: hash.clone(),
        api_base_url: base.clone(),
        user_id: user_id.clone(),
    };
    {
        let _mutation = state.session_mutation.lock().await;
        if !state.session_identity_is_current(&session).await {
            return Err(
                "SESSION_CHANGED: Flynn session changed before saving the upload receipt.".into(),
            );
        }
        let mut config = state.config.lock().await;
        config.pending_dumps.retain(|dump| {
            !(dump.api_base_url == pending.api_base_url
                && dump.user_id == pending.user_id
                && dump.file == pending.file)
        });
        config.pending_dumps.push(pending.clone());
        config
            .submission_attempts
            .retain(|saved| !same_submission(saved, &attempt));
    }
    state.save().await?;
    let activity = ActivityItem {
        id: receipt.dump_id.clone(),
        file: path.to_string_lossy().into_owned(),
        status: "queued".into(),
        message: "Upload queued for ingestion. Flynn is processing the dump asynchronously.".into(),
        at: now_iso(),
        characters_seen: None,
        characters_updated: None,
        partial: false,
        results: Vec::new(),
    };
    record_activity_fenced(app, state, activity.clone(), &session).await;
    let item = poll_dump(app, state, &pending, &session).await;
    if !state.session_identity_is_current(&session).await {
        return Err("SESSION_CHANGED: Flynn session changed while checking upload status.".into());
    }
    Ok((item, hash, base, user_id, session))
}

async fn authenticated_session(state: &AppState, base: &str) -> Result<SessionIdentity, String> {
    let mut generation = state.session_generation.load(Ordering::SeqCst);
    let token = active_token(state, base)
        .await?
        .ok_or_else(|| "AUTH_REQUIRED:Connect to Flynn to upload this file.".to_string())?;
    let probe = SessionIdentity {
        generation,
        api_url: base.to_string(),
        user_id: String::new(),
        key: String::new(),
        token: token.clone(),
    };
    let response = get_with_retry_fenced(state, &endpoint(base, "/me")?, &probe)
        .await
        .map_err(|error| format!("Could not verify Flynn session: {error}"))?;
    if !state.session_identity_is_current(&probe).await {
        return Err("SESSION_CHANGED: Flynn session changed.".into());
    }
    if response.status() == StatusCode::UNAUTHORIZED {
        match clear_active_token(state, base, &token, generation).await {
            Ok(true) => {}
            Ok(false) => return Err("SESSION_CHANGED: Flynn session changed.".into()),
            Err(error) => return Err(format!("AUTH_REQUIRED:{error}")),
        }
        return Err("AUTH_REQUIRED:Your Flynn login expired. Connect to Flynn again.".into());
    }
    let account = parse_data_fenced::<Account>(state, response, &probe).await?;
    if !state.session_identity_is_current(&probe).await {
        return Err("SESSION_CHANGED: Flynn session changed.".into());
    }
    let user_id = user_id_key(&account.id)?;
    {
        let _mutation = state.session_mutation.lock().await;
        if !state.session_identity_is_current(&probe).await {
            return Err("SESSION_CHANGED: Flynn session changed.".into());
        }
        let mut config = state.config.lock().await;
        if config
            .authenticated_user_id
            .as_deref()
            .is_some_and(|old| old != user_id)
        {
            generation = state.invalidate_session();
        }
        bind_authenticated_user(&mut config, base, &user_id);
    }
    state.save().await?;
    Ok(session_identity(generation, base, &user_id, &token))
}

async fn find_pending_dump(
    state: &AppState,
    base: &str,
    user_id: &str,
    file: &Path,
) -> Option<PendingDump> {
    let file = file.to_string_lossy();
    state
        .config
        .lock()
        .await
        .pending_dumps
        .iter()
        .find(|dump| dump.api_base_url == base && dump.user_id == user_id && dump.file == file)
        .cloned()
}

async fn resume_pending_dumps<R: Runtime>(app: &AppHandle<R>, state: &AppState) {
    let base = state.config.lock().await.api_base_url.clone();
    let Ok(session) = authenticated_session(state, &base).await else {
        return;
    };
    let pending = {
        let config = state.config.lock().await;
        config
            .pending_dumps
            .iter()
            .filter(|dump| dump.api_base_url == session.api_url && dump.user_id == session.user_id)
            .cloned()
            .collect::<Vec<_>>()
    };
    for dump in pending {
        if !state.session_identity_is_current(&session).await {
            return;
        }
        let item = poll_dump(app, state, &dump, &session).await;
        // Source files may have been deleted or renamed after the POST was
        // accepted. The persisted dump ID is sufficient to finish ingestion;
        // use the old path only for display and successful-hash bookkeeping.
        let _ = commit_sync_result(state, &item, &dump.hash, Path::new(&dump.file), &session).await;
    }
}

async fn poll_dump<R: Runtime>(
    app: &AppHandle<R>,
    state: &AppState,
    pending: &PendingDump,
    session: &SessionIdentity,
) -> ActivityItem {
    let mut activity = ActivityItem {
        id: pending.dump_id.clone(),
        file: pending.file.clone(),
        status: "queued".into(),
        message: "Upload queued for ingestion. Flynn is processing the dump asynchronously.".into(),
        at: now_iso(),
        characters_seen: None,
        characters_updated: None,
        partial: false,
        results: Vec::new(),
    };
    let url = match endpoint(
        &pending.api_base_url,
        &format!("/dumps/{}", pending.dump_id),
    ) {
        Ok(url) => url,
        Err(error) => {
            activity.message =
                format!("Upload remains queued; could not build status URL: {error}");
            let _ = record_activity_fenced(app, state, activity.clone(), session).await;
            return activity;
        }
    };
    let deadline = tokio::time::Instant::now() + Duration::from_secs(300);
    let mut delay = Duration::from_secs(2);
    loop {
        if !state.session_identity_is_current(session).await {
            activity.message = "Status polling was cancelled because the Flynn session changed. The accepted dump remains saved and will not be re-uploaded.".into();
            return activity;
        }
        if tokio::time::Instant::now() >= deadline {
            activity.message = "Ingestion is still pending. Flynn Desktop saved the dump ID and will resume checking without uploading the file again.".into();
            let _ = record_activity_fenced(app, state, activity.clone(), session).await;
            return activity;
        }
        let mut cancelled = state.session_events.subscribe();
        if !state.session_identity_is_current(session).await {
            activity.message = "Status polling was cancelled because the Flynn session changed. The accepted dump remains saved and will not be re-uploaded.".into();
            return activity;
        }
        tokio::select! { biased; _ = cancelled.changed() => {
            activity.message = "Status polling was cancelled because the Flynn session changed. The accepted dump remains saved and will not be re-uploaded.".into();
            return activity;
        }, _ = tokio::time::sleep(delay) => {} }
        let response = match get_with_retry_fenced(state, &url, session).await {
            Ok(response) => response,
            Err(error) => {
                if error.starts_with("SESSION_CHANGED:") {
                    activity.message = "Status polling was cancelled because the Flynn session changed. The accepted dump remains saved and will not be re-uploaded.".into();
                    return activity;
                }
                activity.message =
                    format!("Ingestion remains pending; status check will resume: {error}");
                if !record_activity_fenced(app, state, activity.clone(), session).await {
                    activity.message = "Status polling was cancelled because the Flynn session changed. The accepted dump remains saved and will not be re-uploaded.".into();
                    return activity;
                }
                delay = (delay * 2).min(Duration::from_secs(30));
                continue;
            }
        };
        if !state.session_identity_is_current(session).await {
            activity.message = "Status polling was cancelled because the Flynn session changed. The accepted dump remains saved and will not be re-uploaded.".into();
            return activity;
        }
        if response.status() == StatusCode::UNAUTHORIZED {
            let cleared = clear_active_token(
                state,
                &pending.api_base_url,
                &session.token,
                session.generation,
            )
            .await;
            match cleared {
                Ok(true) => {}
                Ok(false) => {
                    activity.message = "Status polling was cancelled because the Flynn session changed. The accepted dump remains saved and will not be re-uploaded.".into();
                    return activity;
                }
                Err(error) => {
                    activity.status = "reauthentication_required".into();
                    activity.message = format!(
                        "{error} The accepted dump remains saved and will not be re-uploaded."
                    );
                    return activity;
                }
            }
            activity.status = "reauthentication_required".into();
            activity.message = "Your Flynn login expired while the dump was processing. Reconnect to resume status checks; the accepted dump will not be re-uploaded.".into();
            return activity;
        }
        let status_code = response.status();
        let retry_header = response
            .headers()
            .get(reqwest::header::RETRY_AFTER)
            .and_then(|value| value.to_str().ok())
            .map(str::to_string);
        let value: Value = match response_json_fenced(state, response, session).await {
            Ok(value) => value,
            Err(error) if error.starts_with("SESSION_CHANGED:") => {
                activity.message = "Status polling was cancelled because the Flynn session changed. The accepted dump remains saved and will not be re-uploaded.".into();
                return activity;
            }
            Err(_) => {
                if !state.session_identity_is_current(session).await {
                    activity.message = "Status polling was cancelled because the Flynn session changed. The accepted dump remains saved and will not be re-uploaded.".into();
                    return activity;
                }
                activity.message = format!(
                    "Ingestion remains pending; unreadable status response ({status_code})."
                );
                if !record_activity_fenced(app, state, activity.clone(), session).await {
                    activity.message = "Status polling was cancelled because the Flynn session changed. The accepted dump remains saved and will not be re-uploaded.".into();
                    return activity;
                }
                delay = retry_delay(retry_header.as_deref(), 1);
                continue;
            }
        };
        if !state.session_identity_is_current(session).await {
            activity.message = "Status polling was cancelled because the Flynn session changed. The accepted dump remains saved and will not be re-uploaded.".into();
            return activity;
        }
        if !status_code.is_success() {
            activity.message = format!(
                "Ingestion remains pending; {}",
                api_error_message(&value, status_code, &session.token)
            );
            if !record_activity_fenced(app, state, activity.clone(), session).await {
                activity.message = "Status polling was cancelled because the Flynn session changed. The accepted dump remains saved and will not be re-uploaded.".into();
                return activity;
            }
            delay = retry_delay(retry_header.as_deref(), 1);
            continue;
        }
        let data = value.get("data").unwrap_or(&value);
        match data.get("status").and_then(Value::as_str).unwrap_or("") {
            "queued" | "processing" => {
                let next_status = data
                    .get("status")
                    .and_then(Value::as_str)
                    .unwrap_or("queued");
                if activity.status != next_status {
                    activity.status = next_status.into();
                    activity.message = if next_status == "processing" {
                        "Flynn is ingesting this dump."
                    } else {
                        "Upload queued for ingestion."
                    }
                    .into();
                    activity.at = now_iso();
                    if !record_activity_fenced(app, state, activity.clone(), session).await {
                        activity.message = "Status polling was cancelled because the Flynn session changed. The accepted dump remains saved and will not be re-uploaded.".into();
                        return activity;
                    }
                }
                delay = retry_delay(retry_header.as_deref(), 1);
            }
            "failed" => {
                let result = ActivityItem {
                    id: pending.dump_id.clone(),
                    file: pending.file.clone(),
                    status: "failed".into(),
                    message: data
                        .pointer("/error/message")
                        .and_then(Value::as_str)
                        .unwrap_or("Flynn could not ingest this dump.")
                        .replace(&session.token, "[redacted]"),
                    at: now_iso(),
                    characters_seen: data.get("charactersSeen").and_then(Value::as_u64),
                    characters_updated: data.get("charactersUpdated").and_then(Value::as_u64),
                    partial: false,
                    results: parse_character_results(data, &session.token),
                };
                if !record_activity_fenced(app, state, result.clone(), session).await {
                    activity.message = "Status polling was cancelled because the Flynn session changed. The accepted dump remains saved and will not be re-uploaded.".into();
                    return activity;
                }
                return result;
            }
            "completed" => {
                let results = parse_character_results(data, &session.token);
                let partial = has_partial_results(&results);
                let result = ActivityItem {
                    id: pending.dump_id.clone(),
                    file: pending.file.clone(),
                    status: "completed".into(),
                    message: if partial { "Dump completed with partial character results. Review the per-character details and retry after correcting the listed issue." } else { "Dump ingested successfully." }.into(),
                    at: now_iso(),
                    characters_seen: data.get("charactersSeen").and_then(Value::as_u64),
                    characters_updated: data.get("charactersUpdated").and_then(Value::as_u64),
                    partial,
                    results,
                };
                if !record_activity_fenced(app, state, result.clone(), session).await {
                    activity.message = "Status polling was cancelled because the Flynn session changed. The accepted dump remains saved and will not be re-uploaded.".into();
                    return activity;
                }
                return result;
            }
            other => {
                activity.status = "failed".into();
                activity.message = format!(
                    "Flynn returned an unknown dump status '{}'.",
                    other.replace(&session.token, "[redacted]")
                );
                if !record_activity_fenced(app, state, activity.clone(), session).await {
                    activity.message = "Status polling was cancelled because the Flynn session changed. The accepted dump remains saved and will not be re-uploaded.".into();
                    return activity;
                }
                return activity;
            }
        }
    }
}

async fn wait_for_file_stability(path: &Path) -> Result<(), String> {
    let mut previous_size = None;
    let mut stable_samples = 0;
    for _ in 0..8 {
        tokio::time::sleep(Duration::from_millis(500)).await;
        let current_signature = fs::metadata(path).ok().map(|metadata| {
            let modified = metadata
                .modified()
                .ok()
                .and_then(|time| time.duration_since(std::time::UNIX_EPOCH).ok())
                .map(|duration| duration.as_nanos())
                .unwrap_or_default();
            (metadata.len(), modified)
        });
        stable_samples = update_stability(previous_size, current_signature, stable_samples);
        previous_size = current_signature;
        if stable_samples >= 2 {
            return Ok(());
        }
    }
    if previous_size.is_some() {
        Err("Flynn.lua is still changing. Wait for WoW to finish writing, then retry.".into())
    } else {
        Err("Flynn.lua could not be found or read. Check the WoW folder and retry.".into())
    }
}

fn update_stability(
    previous: Option<(u64, u128)>,
    current: Option<(u64, u128)>,
    stable_samples: usize,
) -> usize {
    if current.is_some() && current == previous {
        stable_samples + 1
    } else {
        0
    }
}

fn has_partial_results(results: &[CharacterResult]) -> bool {
    results.iter().any(|result| {
        !matches!(
            result.status.as_deref(),
            Some("updated" | "claimable" | "created" | "completed" | "success")
        )
    })
}

fn should_skip_upload(successful_hash: Option<&str>, current_hash: &str) -> bool {
    successful_hash == Some(current_hash)
}

fn valid_upload_receipt(http_status: StatusCode, dump_status: &str) -> bool {
    (http_status == StatusCode::ACCEPTED || http_status.is_success())
        && matches!(dump_status, "queued" | "processing")
}

fn parse_character_results(data: &Value, token: &str) -> Vec<CharacterResult> {
    data.get("results")
        .and_then(Value::as_array)
        .into_iter()
        .flatten()
        .map(|result| CharacterResult {
            identity: result
                .get("identity")
                .map(|value| redact_value(value, token)),
            status: result
                .get("status")
                .and_then(Value::as_str)
                .map(|value| value.replace(token, "[redacted]")),
            code: result
                .get("code")
                .and_then(Value::as_str)
                .map(|value| value.replace(token, "[redacted]")),
            message: result
                .get("message")
                .and_then(Value::as_str)
                .map(|value| value.replace(token, "[redacted]")),
        })
        .collect()
}

fn redact_value(value: &Value, secret: &str) -> Value {
    match value {
        Value::String(text) => Value::String(text.replace(secret, "[redacted]")),
        Value::Array(values) => Value::Array(
            values
                .iter()
                .map(|value| redact_value(value, secret))
                .collect(),
        ),
        Value::Object(values) => Value::Object(
            values
                .iter()
                .map(|(key, value)| {
                    (
                        key.replace(secret, "[redacted]"),
                        redact_value(value, secret),
                    )
                })
                .collect(),
        ),
        _ => value.clone(),
    }
}

async fn record_activity_fenced<R: Runtime>(
    app: &AppHandle<R>,
    state: &AppState,
    item: ActivityItem,
    session: &SessionIdentity,
) -> bool {
    let _mutation = state.session_mutation.lock().await;
    if !state.session_identity_is_current(session).await {
        return false;
    }
    {
        let mut config = state.config.lock().await;
        if let Some(existing) = config
            .activity
            .iter_mut()
            .find(|existing| existing.id == item.id)
        {
            *existing = item.clone();
        } else {
            config.activity.insert(0, item.clone());
        }
        config.activity.truncate(100);
    }
    let _ = state.save().await;
    let _ = app.emit("sync-event", &item);
    true
}

async fn record_activity_generation_fenced<R: Runtime>(
    app: &AppHandle<R>,
    state: &AppState,
    item: ActivityItem,
    generation: u64,
    api_url: &str,
) -> bool {
    let _mutation = state.session_mutation.lock().await;
    let active_api = state.config.lock().await.api_base_url.clone();
    if state.session_generation.load(Ordering::SeqCst) != generation || active_api != api_url {
        return false;
    }
    {
        let mut config = state.config.lock().await;
        if let Some(existing) = config
            .activity
            .iter_mut()
            .find(|existing| existing.id == item.id)
        {
            *existing = item.clone();
        } else {
            config.activity.insert(0, item.clone());
        }
        config.activity.truncate(100);
    }
    let _ = state.save().await;
    let _ = app.emit("sync-event", &item);
    true
}

fn now_iso() -> String {
    use std::time::{SystemTime, UNIX_EPOCH};
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|duration| duration.as_secs().to_string())
        .unwrap_or_default()
}

async fn refresh_watcher(app: &AppHandle, state: &AppState) -> Result<(), String> {
    let (auto_sync, root) = {
        let config = state.config.lock().await;
        (config.auto_sync, config.wow_root.clone())
    };
    let watcher = build_watcher(state.file_events.clone(), auto_sync, root.as_deref())?;
    {
        let mut watcher_slot = state
            .watcher
            .lock()
            .map_err(|_| "Filesystem watcher lock was poisoned.".to_string())?;
        *watcher_slot = watcher;
    }
    reconcile_files(state, true).await;
    let _ = app.emit("files-changed", state.files().await);
    Ok(())
}

fn build_watcher(
    tx: mpsc::UnboundedSender<PathBuf>,
    auto_sync: bool,
    root: Option<&Path>,
) -> Result<Option<RecommendedWatcher>, String> {
    if !auto_sync {
        return Ok(None);
    }
    let Some(root) = root else {
        return Ok(None);
    };
    let mut watcher = notify::recommended_watcher(move |result: notify::Result<Event>| {
        if let Ok(event) = result {
            if matches!(event.kind, EventKind::Create(_) | EventKind::Modify(_)) {
                for path in event
                    .paths
                    .into_iter()
                    .filter(|path| path.file_name().is_some_and(|name| name == "Flynn.lua"))
                {
                    let _ = tx.send(path);
                }
            }
        }
    })
    .map_err(|error| format!("Could not watch WoW files: {error}"))?;
    watcher
        .watch(root, RecursiveMode::Recursive)
        .map_err(|error| format!("Could not watch the selected WoW directory: {error}"))?;
    Ok(Some(watcher))
}

async fn reconcile_files(state: &AppState, include_pending: bool) {
    let (api_url, user_id, root) = {
        let config = state.config.lock().await;
        (
            config.credential_api_url.clone(),
            config.authenticated_user_id.clone(),
            config.wow_root.clone(),
        )
    };
    let Some(root) = root else {
        return;
    };
    let files = state.files().await;
    for file in files.into_iter().filter(|file| file.enabled) {
        let path = PathBuf::from(&file.path);
        let (_, hash) = match read_file_with_hash_under_root(&root, &path) {
            Ok(result) => result,
            Err(_) => continue,
        };
        let canonical_path = fs::canonicalize(&path)
            .unwrap_or_else(|_| path.clone())
            .to_string_lossy()
            .into_owned();
        let should_queue = {
            let config = state.config.lock().await;
            let pending = api_url
                .as_deref()
                .zip(user_id.as_deref())
                .is_some_and(|(api, user)| {
                    config.pending_dumps.iter().any(|dump| {
                        dump.api_base_url == api && dump.user_id == user && dump.file == file.path
                    })
                });
            let changed = api_url
                .as_deref()
                .zip(user_id.as_deref())
                .is_some_and(|(api, user)| {
                    config
                        .successful_hashes
                        .get(&sync_hash_key(api, user, &path))
                        != Some(&hash)
                });
            api_url
                .as_deref()
                .zip(user_id.as_deref())
                .is_some_and(|(api, user)| {
                    should_reconcile_file(
                        include_pending,
                        pending,
                        changed,
                        &config.submission_attempts,
                        &canonical_path,
                        &hash,
                        api,
                        user,
                    )
                })
        };
        if should_queue {
            let _ = state.file_events.send(path);
        }
    }
}

fn spawn_file_worker(app: AppHandle, mut receiver: mpsc::UnboundedReceiver<PathBuf>) {
    tauri::async_runtime::spawn(async move {
        let mut dirty = BTreeSet::new();
        loop {
            if dirty.is_empty() {
                match receiver.recv().await {
                    Some(path) => {
                        dirty.insert(path);
                    }
                    None => break,
                }
            }
            while let Ok(Some(path)) =
                tokio::time::timeout(Duration::from_millis(1800), receiver.recv()).await
            {
                dirty.insert(path);
            }
            let state = app.state::<AppState>();
            while let Some(path) = dirty.pop_first() {
                let config = state.config.lock().await;
                let active = config.auto_sync;
                let root = config.wow_root.clone();
                let disabled = config.disabled_scopes.clone();
                drop(config);
                if !active || !root.as_ref().is_some_and(|root| path.starts_with(root)) {
                    continue;
                }
                let known = discover_files(root.as_deref().unwrap(), &disabled)
                    .iter()
                    .any(|file| Path::new(&file.path) == path && file.enabled);
                if known {
                    let _ = sync_file(&app, &state, &path.to_string_lossy(), false, false).await;
                }
                // Preserve all events collected during an upload and also reconcile the current
                // snapshots in case the OS watcher coalesced or omitted a notification.
                reconcile_files(&state, false).await;
                while let Ok(path) = receiver.try_recv() {
                    dirty.insert(path);
                }
            }
        }
    });
}

#[tauri::command]
async fn rescan(
    app: AppHandle,
    state: tauri::State<'_, AppState>,
) -> Result<DesktopStatus, String> {
    refresh_watcher(&app, &state).await?;
    get_status(state).await
}

pub fn run() {
    let (file_tx, file_rx) = mpsc::unbounded_channel();
    let builder = tauri::Builder::default()
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_autostart::Builder::new().build())
        .invoke_handler(tauri::generate_handler![
            get_status,
            connect,
            update_settings,
            set_sync_scope,
            set_launch_at_login,
            logout,
            upload_now,
            retry_ambiguous_upload,
            rescan
        ]);
    builder
        .setup(move |app| {
            let config_dir = app.path().app_config_dir()?;
            let state = AppState::load(
                config_dir.join("settings.json"),
                file_tx.clone(),
                Arc::new(NativeCredentialStore),
            );
            app.manage(state);
            spawn_file_worker(app.handle().clone(), file_rx);
            setup_tray(app)?;
            let app_handle = app.handle().clone();
            tauri::async_runtime::spawn(async move {
                let state = app_handle.state::<AppState>();
                let _ = refresh_watcher(&app_handle, &state).await;
                resume_pending_dumps(&app_handle, &state).await;
            });
            Ok(())
        })
        .on_window_event(|window, event| {
            if let WindowEvent::CloseRequested { api, .. } = event {
                if window
                    .state::<AppState>()
                    .config
                    .try_lock()
                    .map(|config| config.minimize_to_tray)
                    .unwrap_or(true)
                {
                    api.prevent_close();
                    let _ = window.hide();
                } else {
                    window.app_handle().exit(0);
                }
            }
        })
        .run(tauri::generate_context!())
        .expect("error while running Flynn Desktop");
}

fn setup_tray(app: &mut tauri::App) -> tauri::Result<()> {
    use tauri::{
        menu::{Menu, MenuItem},
        tray::TrayIconBuilder,
    };
    let open = MenuItem::with_id(app, "open", "Open Flynn", true, None::<&str>)?;
    let upload = MenuItem::with_id(app, "upload", "Upload Now", true, None::<&str>)?;
    let quit = MenuItem::with_id(app, "quit", "Quit Flynn", true, None::<&str>)?;
    let menu = Menu::with_items(app, &[&open, &upload, &quit])?;
    let mut builder = TrayIconBuilder::new();
    if let Some(icon) = app.default_window_icon().cloned() {
        builder = builder.icon(icon);
    }
    builder
        .menu(&menu)
        .tooltip("Flynn Desktop")
        .on_menu_event(|app, event| match event.id().as_ref() {
            "open" => {
                if let Some(window) = app.get_webview_window("main") {
                    let _ = window.show();
                    let _ = window.set_focus();
                }
            }
            "upload" => {
                let app = app.clone();
                tauri::async_runtime::spawn(async move {
                    let state = app.state::<AppState>();
                    let _ = upload_now(app.clone(), state).await;
                });
            }
            "quit" => app.exit(0),
            _ => {}
        })
        .build(app)?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[derive(Default)]
    struct MockCredentialStore(Mutex<HashMap<String, String>>);

    impl CredentialStore for MockCredentialStore {
        fn get(&self, api_url: &str) -> Result<Option<String>, String> {
            Ok(self.0.lock().unwrap().get(api_url).cloned())
        }

        fn set(&self, api_url: &str, token: &str) -> Result<(), String> {
            self.0
                .lock()
                .unwrap()
                .insert(api_url.to_string(), token.to_string());
            Ok(())
        }

        fn delete(&self, api_url: &str) -> Result<(), String> {
            self.0.lock().unwrap().remove(api_url);
            Ok(())
        }
    }

    fn mock_app_handle() -> AppHandle<tauri::test::MockRuntime> {
        tauri::test::mock_builder()
            .build(tauri::test::mock_context(tauri::test::noop_assets()))
            .expect("mock Tauri app should build")
            .handle()
            .clone()
    }

    fn write_authenticated_settings(path: &Path, api_url: &str, user_id: &str) {
        let config = Config {
            api_base_url: api_url.into(),
            credential_api_url: Some(api_url.into()),
            authenticated_user_id: Some(user_id.into()),
            ..Config::default()
        };
        fs::write(path, serde_json::to_vec_pretty(&config).unwrap()).unwrap();
    }

    #[derive(Default)]
    struct DeleteFailureCredentialStore(Mutex<HashMap<String, String>>);

    impl CredentialStore for DeleteFailureCredentialStore {
        fn get(&self, api_url: &str) -> Result<Option<String>, String> {
            Ok(self.0.lock().unwrap().get(api_url).cloned())
        }

        fn set(&self, api_url: &str, token: &str) -> Result<(), String> {
            self.0
                .lock()
                .unwrap()
                .insert(api_url.to_string(), token.to_string());
            Ok(())
        }

        fn delete(&self, _api_url: &str) -> Result<(), String> {
            Err("backend diagnostic contains FLYNN_KEYRING_SECRET_SENTINEL".into())
        }
    }

    #[derive(Default)]
    struct OldServerDeleteFailureStore(Mutex<HashMap<String, String>>);

    impl CredentialStore for OldServerDeleteFailureStore {
        fn get(&self, api_url: &str) -> Result<Option<String>, String> {
            Ok(self.0.lock().unwrap().get(api_url).cloned())
        }

        fn set(&self, api_url: &str, token: &str) -> Result<(), String> {
            self.0.lock().unwrap().insert(api_url.into(), token.into());
            Ok(())
        }

        fn delete(&self, api_url: &str) -> Result<(), String> {
            if api_url == "https://old.example.test" {
                return Err("backend diagnostic contains FLYNN_KEYRING_SECRET_SENTINEL".into());
            }
            self.0.lock().unwrap().remove(api_url);
            Ok(())
        }
    }

    async fn read_test_http_request(socket: &mut tokio::net::TcpStream) -> (String, String, Value) {
        let mut bytes = Vec::new();
        let mut buffer = [0; 4096];
        let (header_end, content_length) = loop {
            let read = socket.read(&mut buffer).await.unwrap();
            assert_ne!(read, 0, "test HTTP request ended before its headers");
            bytes.extend_from_slice(&buffer[..read]);
            if let Some(header_end) = bytes.windows(4).position(|part| part == b"\r\n\r\n") {
                let header_end = header_end + 4;
                let headers = String::from_utf8_lossy(&bytes[..header_end]);
                let content_length = headers
                    .lines()
                    .find_map(|line| {
                        line.to_ascii_lowercase()
                            .strip_prefix("content-length:")
                            .and_then(|value| value.trim().parse::<usize>().ok())
                    })
                    .unwrap_or(0);
                break (header_end, content_length);
            }
        };
        while bytes.len() < header_end + content_length {
            let read = socket.read(&mut buffer).await.unwrap();
            assert_ne!(read, 0, "test HTTP request ended before its body");
            bytes.extend_from_slice(&buffer[..read]);
        }
        let headers = String::from_utf8_lossy(&bytes[..header_end]).into_owned();
        let request_line = headers.lines().next().unwrap().to_string();
        let body = if content_length == 0 {
            Value::Null
        } else {
            serde_json::from_slice(&bytes[header_end..header_end + content_length]).unwrap()
        };
        (request_line, headers, body)
    }

    async fn write_test_http_response(socket: &mut tokio::net::TcpStream, body: &str) {
        socket
            .write_all(
                format!(
                    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
                    body.len(),
                    body
                )
                .as_bytes(),
            )
            .await
            .unwrap();
    }

    async fn mock_pkce_api(
        listener: TcpListener,
        expected_challenge: tokio::sync::oneshot::Receiver<String>,
        me_requests: usize,
    ) {
        let expected_challenge = expected_challenge.await.unwrap();
        for request_index in 0..=me_requests {
            let (mut socket, _) = listener.accept().await.unwrap();
            let (request_line, headers, body) = read_test_http_request(&mut socket).await;
            let path = request_line.split_whitespace().nth(1).unwrap();
            if request_index == 0 {
                assert_eq!(path, "/api/v1/auth/desktop/token");
                assert_eq!(body.get("code").and_then(Value::as_str), Some("test-code"));
                let verifier = body.get("codeVerifier").and_then(Value::as_str).unwrap();
                assert_eq!(pkce_challenge(verifier), expected_challenge);
                write_test_http_response(
                    &mut socket,
                    r#"{"data":{"accessToken":"issued-test-token"}}"#,
                )
                .await;
            } else {
                assert_eq!(path, "/api/v1/me");
                assert!(headers
                    .to_ascii_lowercase()
                    .contains("authorization: bearer issued-test-token"));
                write_test_http_response(
                    &mut socket,
                    r#"{"data":{"id":"test-user","name":"Test Crafter","email":"crafter@example.test"}}"#,
                )
                .await;
            }
        }
    }

    async fn reconciliation_fixture(
        attempt_state: &str,
    ) -> (
        tempfile::TempDir,
        AppState,
        mpsc::UnboundedReceiver<PathBuf>,
        PathBuf,
        String,
        Arc<MockCredentialStore>,
    ) {
        let directory = tempfile::tempdir().unwrap();
        let root = directory.path().join("World of Warcraft");
        let file = root
            .join("_retail_")
            .join("WTF/Account/test-account/SavedVariables/Flynn.lua");
        std::fs::create_dir_all(file.parent().unwrap()).unwrap();
        std::fs::write(&file, "FlynnDB = { value = 1 }\n").unwrap();
        let root = fs::canonicalize(root).unwrap();
        let file = fs::canonicalize(file).unwrap();
        let (_, hash) = read_file_with_hash_under_root(&root, &file).unwrap();
        let (sender, receiver) = mpsc::unbounded_channel();
        let credentials = Arc::new(MockCredentialStore::default());
        let state = AppState::load(
            directory.path().join("settings.json"),
            sender,
            credentials.clone(),
        );
        {
            let mut config = state.config.lock().await;
            config.wow_root = Some(root);
            config.api_base_url = "https://api.example.test".into();
            config.credential_api_url = Some("https://api.example.test".into());
            config.authenticated_user_id = Some("user-a".into());
            config.submission_attempts.push(SubmissionAttempt {
                file: file.to_string_lossy().into_owned(),
                hash: hash.clone(),
                api_base_url: "https://api.example.test".into(),
                user_id: "user-a".into(),
                session_key: "session-fingerprint".into(),
                state: attempt_state.into(),
            });
        }
        (directory, state, receiver, file, hash, credentials)
    }

    #[tokio::test]
    async fn invalid_wow_root_is_rejected_before_settings_are_changed() {
        let directory = tempfile::tempdir().unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let state = AppState::load(
            directory.path().join("settings.json"),
            sender,
            Arc::new(MockCredentialStore::default()),
        );
        let before = state.config.lock().await.clone();
        let result = settings_candidate(
            &before,
            AppSettings {
                api_base_url: Some("https://changed.example.test".into()),
                wow_root: Some(directory.path().to_string_lossy().into_owned()),
                auto_sync: Some(true),
                minimize_to_tray: Some(false),
            },
        );
        assert!(result.unwrap_err().contains("supported client flavor"));
        assert_eq!(
            serde_json::to_value(&*state.config.lock().await).unwrap(),
            serde_json::to_value(&before).unwrap()
        );
        assert!(!directory.path().join("settings.json").exists());
    }

    #[tokio::test]
    async fn keyring_delete_failure_preserves_settings_and_working_session() {
        let directory = tempfile::tempdir().unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let credentials = Arc::new(DeleteFailureCredentialStore::default());
        credentials
            .set("https://old.example.test", "working-token")
            .unwrap();
        let state = AppState::load(
            directory.path().join("settings.json"),
            sender,
            credentials.clone(),
        );
        {
            let mut config = state.config.lock().await;
            config.api_base_url = "https://old.example.test".into();
            config.credential_api_url = Some("https://old.example.test".into());
            config.authenticated_user_id = Some("user-a".into());
            config
                .successful_hashes
                .insert("source".into(), "hash".into());
        }
        let before = state.config.lock().await.clone();
        let generation = state.session_generation.load(Ordering::SeqCst);
        let mut candidate = before.clone();
        candidate.api_base_url = "https://new.example.test".into();
        candidate.credential_api_url = None;
        candidate.authenticated_user_id = None;
        candidate.successful_hashes.clear();

        let error = commit_settings_candidate(&state, candidate)
            .await
            .unwrap_err();
        assert!(error.contains("CREDENTIAL_STORE_DELETE_FAILED"));
        assert!(!error.contains("FLYNN_KEYRING_SECRET_SENTINEL"));
        assert_eq!(
            serde_json::to_value(&*state.config.lock().await).unwrap(),
            serde_json::to_value(&before).unwrap()
        );
        assert_eq!(state.session_generation.load(Ordering::SeqCst), generation);
        assert_eq!(
            credentials
                .get("https://old.example.test")
                .unwrap()
                .as_deref(),
            Some("working-token")
        );
        assert!(!directory.path().join("settings.json").exists());
    }

    #[tokio::test]
    async fn cross_server_login_reports_old_key_deletion_failure_and_rolls_back_new_key() {
        let directory = tempfile::tempdir().unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let credentials = Arc::new(OldServerDeleteFailureStore::default());
        credentials
            .set("https://old.example.test", "old-token")
            .unwrap();
        let state = AppState::load(
            directory.path().join("settings.json"),
            sender,
            credentials.clone(),
        );
        {
            let mut config = state.config.lock().await;
            config.api_base_url = "https://old.example.test".into();
            config.credential_api_url = Some("https://old.example.test".into());
            config.authenticated_user_id = Some("old-user".into());
        }

        let _mutation = state.session_mutation.lock().await;
        let error = install_authenticated_session_locked(
            &state,
            "https://new.example.test",
            "new-token",
            "new-user",
        )
        .await
        .unwrap_err();

        assert!(error.contains("Could not remove the previous Flynn server credential"));
        assert!(error.contains("CREDENTIAL_STORE_DELETE_FAILED"));
        assert!(!error.contains("FLYNN_KEYRING_SECRET_SENTINEL"));
        let config = state.config.lock().await;
        assert_eq!(config.api_base_url, "https://old.example.test");
        assert_eq!(
            config.credential_api_url.as_deref(),
            Some("https://old.example.test")
        );
        assert_eq!(config.authenticated_user_id.as_deref(), Some("old-user"));
        assert_eq!(
            credentials
                .get("https://old.example.test")
                .unwrap()
                .as_deref(),
            Some("old-token")
        );
        assert_eq!(credentials.get("https://new.example.test").unwrap(), None);
        assert!(!directory.path().join("settings.json").exists());
    }

    #[tokio::test]
    async fn pkce_connect_persists_authenticated_session_and_rolls_back_on_save_failure() {
        tokio::time::timeout(Duration::from_secs(10), async {
            async fn connect_through_mock_browser(
                state: &AppState,
                expected_me_requests: usize,
            ) -> (Result<Account, String>, tokio::task::JoinHandle<()>, String) {
                let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
                let api_url = format!("http://{}", listener.local_addr().unwrap());
                let (challenge_tx, challenge_rx) = tokio::sync::oneshot::channel();
                let server = tokio::spawn(mock_pkce_api(
                    listener,
                    challenge_rx,
                    expected_me_requests,
                ));
                let (callback_tx, callback_rx) = tokio::sync::oneshot::channel();
                let result = connect_with_browser(api_url, state, move |authorization_url| {
                    let authorization = Url::parse(authorization_url)
                        .map_err(|error| error.to_string())?;
                    assert_eq!(authorization.path(), "/api/v1/auth/desktop/authorize");
                    let query: HashMap<String, String> = authorization
                        .query_pairs()
                        .map(|(key, value)| (key.into_owned(), value.into_owned()))
                        .collect();
                    let challenge = query.get("codeChallenge").unwrap().clone();
                    let callback_state = query.get("state").unwrap().clone();
                    assert_eq!(query.get("redirectUri").map(String::as_str), Some(CALLBACK_URI));
                    challenge_tx.send(challenge).map_err(|_| "API test stopped".to_string())?;
                    tokio::spawn(async move {
                        let callback_result = async {
                            let mut socket = tokio::net::TcpStream::connect("127.0.0.1:51337")
                                .await
                                .map_err(|error| error.to_string())?;
                            socket
                                .write_all(
                                    format!(
                                        "GET /oauth/callback?code=test-code&state={callback_state} HTTP/1.1\r\nHost: 127.0.0.1:51337\r\nConnection: close\r\n\r\n"
                                    )
                                    .as_bytes(),
                                )
                                .await
                                .map_err(|error| error.to_string())?;
                            let mut response = Vec::new();
                            socket
                                .read_to_end(&mut response)
                                .await
                                .map_err(|error| error.to_string())?;
                            if !String::from_utf8_lossy(&response).starts_with("HTTP/1.1 200") {
                                return Err("test browser callback was rejected".to_string());
                            }
                            Ok(String::from_utf8_lossy(&response).into_owned())
                        }
                        .await;
                        let _ = callback_tx.send(callback_result);
                    });
                    Ok(())
                })
                .await;
                let browser_response = callback_rx.await.unwrap().unwrap();
                (result, server, browser_response)
            }

            let success_directory = tempfile::tempdir().unwrap();
            let (sender, _receiver) = mpsc::unbounded_channel();
            let credentials = Arc::new(MockCredentialStore::default());
            let success_state = AppState::load(
                success_directory.path().join("settings.json"),
                sender,
                credentials.clone(),
            );
            let account = connect_through_mock_browser(
                &success_state,
                2,
            )
            .await;
            let (account, api_server, browser_response) = account;
            let account = account.expect("PKCE connect should complete without deadlocking");
            assert!(browser_response.contains("connected successfully"));
            assert!(!browser_response.contains("could not finish connecting"));
            assert_eq!(account.name, "Test Crafter");
            assert_eq!(
                credentials
                    .get(&success_state.config.lock().await.api_base_url)
                    .unwrap()
                    .as_deref(),
                Some("issued-test-token")
            );
            let persisted: Value = serde_json::from_slice(
                &fs::read(success_directory.path().join("settings.json")).unwrap(),
            )
            .unwrap();
            assert_eq!(persisted["authenticatedUserId"], "test-user");
            assert_eq!(persisted["credentialApiUrl"], success_state.config.lock().await.api_base_url);
            assert!(persisted.get("accessToken").is_none());
            let status = get_status_for_state(&success_state)
                .await
                .expect("the persisted credential should produce authenticated status");
            assert!(status.authenticated);
            assert_eq!(status.account.unwrap().email, "crafter@example.test");
            api_server.await.unwrap();

            let failure_directory = tempfile::tempdir().unwrap();
            let invalid_settings_path = failure_directory.path().join("settings.json");
            fs::create_dir(&invalid_settings_path).unwrap();
            let (sender, _receiver) = mpsc::unbounded_channel();
            let failed_credentials = Arc::new(MockCredentialStore::default());
            let failure_state = AppState::load(
                invalid_settings_path,
                sender,
                failed_credentials.clone(),
            );
            let error = connect_through_mock_browser(
                &failure_state,
                1,
            )
            .await;
            let (error, api_server, browser_response) = error;
            api_server.await.unwrap();
            let error = error.expect_err("failed settings persistence must reject connect");
            assert!(browser_response.contains("could not finish connecting"));
            assert!(!browser_response.contains("connected successfully"));
            assert!(error.contains("Could not save the Flynn session"));
            assert!(failed_credentials
                .get(&failure_state.config.lock().await.api_base_url)
                .unwrap()
                .is_none());
            let config = failure_state.config.lock().await;
            assert!(config.credential_api_url.is_none());
            assert!(config.authenticated_user_id.is_none());
            drop(config);
            assert!(
                !get_status_for_state(&failure_state)
                    .await
                    .unwrap()
                    .authenticated,
                "failed connection must not leak an authenticated status"
            );
        })
        .await
        .expect("PKCE connect and rollback must finish within the test timeout");
    }

    #[tokio::test]
    async fn parent_directory_sync_failure_is_committed_with_warning_and_survives_reload() {
        let directory = tempfile::tempdir().unwrap();
        let config_path = directory.path().join("settings.json");
        let (sender, _receiver) = mpsc::unbounded_channel();
        let credentials = Arc::new(MockCredentialStore::default());
        let state = AppState::load(config_path.clone(), sender, credentials.clone());

        state
            .save_with_parent_sync(|_| Err(std::io::Error::other("injected parent sync failure")))
            .await
            .expect("a post-rename sync failure is a committed save, not a rollback trigger");

        let warning = state
            .config
            .lock()
            .await
            .durability_warning
            .clone()
            .unwrap();
        assert!(warning.contains("Settings were saved"));
        assert!(warning.contains("injected parent sync failure"));
        let disk: Value = serde_json::from_slice(&fs::read(&config_path).unwrap()).unwrap();
        assert!(disk["durabilityWarning"]
            .as_str()
            .unwrap()
            .contains("injected parent sync failure"));

        let (sender, _receiver) = mpsc::unbounded_channel();
        let reloaded = AppState::load(config_path, sender, credentials.clone());
        let status = get_status_for_state(&reloaded)
            .await
            .expect("the unauthenticated status should load without a network request");
        assert!(!status.authenticated);
        assert!(status
            .durability_warning
            .as_deref()
            .is_some_and(|warning| warning.contains("injected parent sync failure")));
    }

    #[tokio::test]
    async fn concurrent_saves_snapshot_after_the_serialization_lock_and_keep_latest_attempt() {
        let directory = tempfile::tempdir().unwrap();
        let config_path = directory.path().join("settings.json");
        let (sender, _receiver) = mpsc::unbounded_channel();
        let state = Arc::new(AppState::load(
            config_path.clone(),
            sender,
            Arc::new(MockCredentialStore::default()),
        ));
        let save_guard = state.save_lock.lock().await;
        let saving_state = state.clone();
        let pending_save = tokio::spawn(async move { saving_state.save().await });
        // Let the save operation enqueue on the held serialization lock before
        // the newer submission attempt is committed.
        tokio::task::yield_now().await;
        state
            .config
            .lock()
            .await
            .submission_attempts
            .push(SubmissionAttempt {
                file: "/wow/Flynn.lua".into(),
                hash: "latest-hash".into(),
                api_base_url: "https://api.example.test".into(),
                user_id: "user-a".into(),
                session_key: "session-key".into(),
                state: "submitting".into(),
            });
        drop(save_guard);
        pending_save.await.unwrap().unwrap();

        let (sender, _receiver) = mpsc::unbounded_channel();
        let restarted = AppState::load(
            config_path,
            sender,
            Arc::new(MockCredentialStore::default()),
        );
        let config = restarted.config.lock().await;
        assert_eq!(config.submission_attempts.len(), 1);
        assert_eq!(config.submission_attempts[0].hash, "latest-hash");
        assert_eq!(config.submission_attempts[0].state, "ambiguous");
    }

    #[tokio::test]
    async fn concurrent_settings_scope_and_launch_mutations_do_not_revert_each_other() {
        let directory = tempfile::tempdir().unwrap();
        let config_path = directory.path().join("settings.json");
        let (sender, _receiver) = mpsc::unbounded_channel();
        let state = Arc::new(AppState::load(
            config_path.clone(),
            sender,
            Arc::new(MockCredentialStore::default()),
        ));
        assert!(
            set_sync_scope_config(&state, "unsupported", "test-account", false)
                .await
                .is_err()
        );
        let (snapshot_tx, snapshot_rx) = tokio::sync::oneshot::channel();
        let (continue_tx, continue_rx) = tokio::sync::oneshot::channel();
        let updating_state = state.clone();
        let update = tokio::spawn(async move {
            update_settings_transaction_with(
                &updating_state,
                AppSettings {
                    api_base_url: None,
                    wow_root: None,
                    auto_sync: Some(true),
                    minimize_to_tray: None,
                },
                |_| Ok(()),
                || async move {
                    snapshot_tx.send(()).unwrap();
                    continue_rx.await.unwrap();
                },
            )
            .await
        });
        snapshot_rx.await.unwrap();

        let scope_state = state.clone();
        let scope = tokio::spawn(async move {
            set_sync_scope_config(&scope_state, "retail", "test-account", false).await
        });
        let launch_state = state.clone();
        let launch = tokio::spawn(async move {
            set_launch_at_login_config(&launch_state, true, || Ok(())).await
        });
        // Give both mutation commands time to queue behind the known-held
        // settings transaction before allowing its candidate to commit.
        tokio::task::yield_now().await;
        tokio::task::yield_now().await;
        continue_tx.send(()).unwrap();

        update.await.unwrap().unwrap();
        scope.await.unwrap().unwrap();
        launch.await.unwrap().unwrap();
        set_sync_scope_config(&state, "forever", "camelot-account", false)
            .await
            .unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let persisted = AppState::load(
            config_path,
            sender,
            Arc::new(MockCredentialStore::default()),
        );
        let config = persisted.config.lock().await;
        assert!(config.auto_sync, "settings update should be retained");
        assert!(
            config.disabled_scopes.contains("_retail_/test-account"),
            "scope update should not be overwritten by the settings snapshot"
        );
        assert!(config.disabled_scopes.contains("_forever_/camelot-account"));
        assert!(config.launch_at_login, "launch setting should be retained");
    }

    #[test]
    fn api_url_validation_strips_trailing_slash_and_rejects_credentials() {
        assert_eq!(
            api_base("https://flynn.example.test/").unwrap(),
            "https://flynn.example.test"
        );
        assert!(api_base("https://user:secret@flynn.example.test").is_err());
        assert!(api_base("file:///tmp/api").is_err());
        assert!(api_base("http://flynn.example.test").is_err());
        assert_eq!(
            api_base("http://127.0.0.1:8000/").unwrap(),
            "http://127.0.0.1:8000"
        );
        assert_eq!(
            endpoint("https://flynn.example.test", "/dumps").unwrap(),
            "https://flynn.example.test/api/v1/dumps"
        );
    }

    #[test]
    fn pkce_challenge_uses_sha256_base64url_without_padding() {
        let challenge = pkce_challenge("sample verifier string");
        assert_eq!(challenge.len(), 43);
        assert!(!challenge.contains('='));
        assert!(challenge
            .chars()
            .all(|character| character.is_ascii_alphanumeric()
                || character == '_'
                || character == '-'));
        assert_eq!(challenge, pkce_challenge("sample verifier string"));
    }

    #[test]
    fn browser_callback_requires_the_exact_pkce_state() {
        assert!(callback_state_matches(
            Some("expected-random-state"),
            "expected-random-state"
        ));
        assert!(!callback_state_matches(
            Some("different-state"),
            "expected-random-state"
        ));
        assert!(!callback_state_matches(None, "expected-random-state"));
    }

    #[test]
    fn credentials_are_bound_to_the_api_base() {
        assert!(credential_matches_api(
            Some("https://api.example.test"),
            "https://api.example.test"
        ));
        assert!(!credential_matches_api(
            Some("https://api.example.test"),
            "https://other.example.test"
        ));
        assert!(!credential_matches_api(None, "https://api.example.test"));
    }

    #[tokio::test]
    async fn injected_credential_store_shares_tokens_without_plaintext_config_fallback() {
        let directory = tempfile::tempdir().unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let credentials = Arc::new(MockCredentialStore::default());
        credentials
            .set("https://api.example.test", "opaque-test-token")
            .unwrap();
        let state = AppState::load(directory.path().join("settings.json"), sender, credentials);
        {
            let mut config = state.config.lock().await;
            config.credential_api_url = Some("https://api.example.test".into());
        }
        assert_eq!(
            active_token(&state, "https://api.example.test")
                .await
                .unwrap()
                .as_deref(),
            Some("opaque-test-token")
        );
        assert_eq!(
            active_token(&state, "https://other.example.test")
                .await
                .unwrap(),
            None
        );
        state.save().await.unwrap();
        let settings = std::fs::read_to_string(directory.path().join("settings.json")).unwrap();
        assert!(!settings.contains("opaque-test-token"));
    }

    #[tokio::test]
    async fn stale_unauthorized_completion_cannot_delete_a_new_login_to_same_api() {
        let directory = tempfile::tempdir().unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let credentials = Arc::new(MockCredentialStore::default());
        credentials
            .set("https://api.example.test", "old-token")
            .unwrap();
        let state = AppState::load(
            directory.path().join("settings.json"),
            sender,
            credentials.clone(),
        );
        state.config.lock().await.credential_api_url = Some("https://api.example.test".into());
        let stale_generation = state.session_generation.load(Ordering::SeqCst);

        {
            let _mutation = state.session_mutation.lock().await;
            state.invalidate_session();
            credentials
                .set("https://api.example.test", "new-token")
                .unwrap();
        }
        clear_active_token(
            &state,
            "https://api.example.test",
            "old-token",
            stale_generation,
        )
        .await
        .unwrap();
        assert_eq!(
            credentials
                .get("https://api.example.test")
                .unwrap()
                .as_deref(),
            Some("new-token")
        );
        assert_eq!(
            active_token(&state, "https://api.example.test")
                .await
                .unwrap()
                .as_deref(),
            Some("new-token")
        );
    }

    #[tokio::test]
    async fn expired_401_commits_signed_out_binding_and_reports_keyring_delete_failure() {
        let directory = tempfile::tempdir().unwrap();
        let config_path = directory.path().join("settings.json");
        write_authenticated_settings(&config_path, "https://api.example.test", "user-a");
        let (sender, _receiver) = mpsc::unbounded_channel();
        let credentials = Arc::new(DeleteFailureCredentialStore::default());
        credentials
            .set("https://api.example.test", "expired-token")
            .unwrap();
        let state = AppState::load(config_path.clone(), sender, credentials.clone());
        {
            let mut config = state.config.lock().await;
            config.api_base_url = "https://api.example.test".into();
            config.credential_api_url = Some("https://api.example.test".into());
            config.authenticated_user_id = Some("user-a".into());
            config
                .successful_hashes
                .insert("source".into(), "hash".into());
        }
        let generation = state.session_generation.load(Ordering::SeqCst);

        let error = clear_active_token_generation(
            &state,
            "https://api.example.test",
            "expired-token",
            generation,
        )
        .await
        .expect_err("keyring deletion failure must be visible to the caller");

        assert!(error.contains("signed you out locally"));
        assert!(error.contains("could not be removed from the OS credential store"));
        assert!(error.contains("CREDENTIAL_STORE_DELETE_FAILED"));
        assert!(!error.contains("FLYNN_KEYRING_SECRET_SENTINEL"));
        assert!(state.session_generation.load(Ordering::SeqCst) > generation);
        assert_eq!(
            credentials
                .get("https://api.example.test")
                .unwrap()
                .as_deref(),
            Some("expired-token")
        );
        let config = state.config.lock().await;
        assert!(config.credential_api_url.is_none());
        assert!(config.authenticated_user_id.is_none());
        assert!(config.successful_hashes.is_empty());
        assert!(config
            .credential_cleanup_warning
            .as_deref()
            .is_some_and(|warning| warning.contains("CREDENTIAL_STORE_DELETE_FAILED")));
        drop(config);

        let (sender, _receiver) = mpsc::unbounded_channel();
        let reloaded = AppState::load(config_path, sender, credentials.clone());
        assert!(active_token(&reloaded, "https://api.example.test")
            .await
            .unwrap()
            .is_none());
        let status = get_status_for_state(&reloaded).await.unwrap();
        assert!(!status.authenticated);
        assert!(status
            .credential_cleanup_warning
            .as_deref()
            .is_some_and(|warning| warning.contains("CREDENTIAL_STORE_DELETE_FAILED")));
        assert_eq!(
            credentials
                .get("https://api.example.test")
                .unwrap()
                .as_deref(),
            Some("expired-token")
        );
    }

    #[tokio::test]
    async fn unauthorized_status_returns_signed_out_with_sanitized_cleanup_warning_immediately() {
        let directory = tempfile::tempdir().unwrap();
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let api_url = format!("http://{}", listener.local_addr().unwrap());
        let config_path = directory.path().join("settings.json");
        write_authenticated_settings(&config_path, &api_url, "user-a");
        let credentials = Arc::new(DeleteFailureCredentialStore::default());
        credentials.set(&api_url, "expired-token").unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let state = AppState::load(config_path.clone(), sender, credentials.clone());

        let api_server = tokio::spawn(async move {
            let (mut socket, _) = listener.accept().await.unwrap();
            let mut request = [0; 2048];
            let _ = socket.read(&mut request).await.unwrap();
            socket
                .write_all(
                    b"HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
                )
                .await
                .unwrap();
        });

        // This is the first status request after the 401. It must not report a
        // spurious session-changed error just because the orphaned keychain
        // entry remains after cleanup failed.
        let status = get_status_for_state(&state)
            .await
            .expect("the 401 status should immediately render signed-out state");
        api_server.await.unwrap();

        assert!(!status.authenticated);
        assert!(status.account.is_none());
        let warning = status
            .credential_cleanup_warning
            .as_deref()
            .expect("cleanup failure should be actionable in the returned status");
        assert!(warning.contains("CREDENTIAL_STORE_DELETE_FAILED"));
        assert!(warning.contains("signed you out locally"));
        let serialized_status = serde_json::to_string(&status).unwrap();
        let persisted_config: Config =
            serde_json::from_slice(&fs::read(&config_path).unwrap()).unwrap();
        assert!(!serialized_status.contains("FLYNN_KEYRING_SECRET_SENTINEL"));
        assert!(!serde_json::to_string(&persisted_config)
            .unwrap()
            .contains("FLYNN_KEYRING_SECRET_SENTINEL"));
        assert!(persisted_config.credential_api_url.is_none());
        assert!(persisted_config.authenticated_user_id.is_none());
        assert_eq!(
            credentials.get(&api_url).unwrap().as_deref(),
            Some("expired-token")
        );
    }

    #[tokio::test]
    async fn successful_same_api_relogin_clears_stale_credential_cleanup_warning() {
        let directory = tempfile::tempdir().unwrap();
        let api_url = "https://api.example.test";
        let config_path = directory.path().join("settings.json");
        write_authenticated_settings(&config_path, api_url, "user-a");
        let credentials = Arc::new(DeleteFailureCredentialStore::default());
        credentials.set(api_url, "expired-token").unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let state = AppState::load(config_path, sender, credentials.clone());
        let generation = state.session_generation.load(Ordering::SeqCst);

        let cleanup_error =
            clear_active_token_generation(&state, api_url, "expired-token", generation)
                .await
                .unwrap_err();
        assert!(cleanup_error.contains("CREDENTIAL_STORE_DELETE_FAILED"));
        assert!(state
            .config
            .lock()
            .await
            .credential_cleanup_warning
            .is_some());

        {
            let _mutation = state.session_mutation.lock().await;
            install_authenticated_session_locked(&state, api_url, "fresh-token", "user-a")
                .await
                .expect("same-API relogin should rebind the new credential");
        }

        let config = state.config.lock().await;
        assert_eq!(config.credential_api_url.as_deref(), Some(api_url));
        assert_eq!(config.authenticated_user_id.as_deref(), Some("user-a"));
        assert!(config.credential_cleanup_warning.is_none());
        drop(config);
        assert_eq!(
            credentials.get(api_url).unwrap().as_deref(),
            Some("fresh-token")
        );
    }

    #[tokio::test]
    async fn logout_pre_rename_save_failure_retains_persisted_session_and_credential() {
        let directory = tempfile::tempdir().unwrap();
        let config_path = directory.path().join("settings.json");
        let api_url = "https://api.example.test";
        write_authenticated_settings(&config_path, api_url, "user-a");
        let (sender, _receiver) = mpsc::unbounded_channel();
        let credentials = Arc::new(MockCredentialStore::default());
        credentials.set(api_url, "working-token").unwrap();
        let state = AppState::load(config_path.clone(), sender, credentials.clone());

        let error =
            logout_with_pre_rename_failure(&state, Some("injected pre-rename save failure".into()))
                .await
                .unwrap_err();
        assert!(error.contains("Could not save logout"));
        assert!(error.contains("injected pre-rename save failure"));
        assert_eq!(
            credentials.get(api_url).unwrap().as_deref(),
            Some("working-token")
        );

        let persisted: Config = serde_json::from_slice(&fs::read(&config_path).unwrap()).unwrap();
        assert_eq!(persisted.credential_api_url.as_deref(), Some(api_url));
        assert_eq!(persisted.authenticated_user_id.as_deref(), Some("user-a"));
        let (sender, _receiver) = mpsc::unbounded_channel();
        let reloaded = AppState::load(config_path, sender, credentials.clone());
        assert_eq!(
            active_token(&reloaded, api_url).await.unwrap().as_deref(),
            Some("working-token")
        );
        let config = reloaded.config.lock().await;
        assert_eq!(config.credential_api_url.as_deref(), Some(api_url));
        assert_eq!(config.authenticated_user_id.as_deref(), Some("user-a"));
    }

    #[tokio::test]
    async fn expired_token_clear_pre_rename_failure_keeps_persisted_binding_and_credential() {
        let directory = tempfile::tempdir().unwrap();
        let config_path = directory.path().join("settings.json");
        let api_url = "https://api.example.test";
        write_authenticated_settings(&config_path, api_url, "user-a");
        let (sender, _receiver) = mpsc::unbounded_channel();
        let credentials = Arc::new(MockCredentialStore::default());
        credentials.set(api_url, "expired-token").unwrap();
        let state = AppState::load(config_path.clone(), sender, credentials.clone());
        let generation = state.session_generation.load(Ordering::SeqCst);

        let error = clear_active_token_generation_with_save_failure(
            &state,
            api_url,
            "expired-token",
            generation,
            Some("injected pre-rename save failure".into()),
        )
        .await
        .unwrap_err();
        assert!(error.contains("Could not save the signed-out Flynn session"));
        assert_eq!(
            credentials.get(api_url).unwrap().as_deref(),
            Some("expired-token")
        );

        let persisted: Config = serde_json::from_slice(&fs::read(&config_path).unwrap()).unwrap();
        assert_eq!(persisted.credential_api_url.as_deref(), Some(api_url));
        assert_eq!(persisted.authenticated_user_id.as_deref(), Some("user-a"));
        let (sender, _receiver) = mpsc::unbounded_channel();
        let reloaded = AppState::load(config_path, sender, credentials.clone());
        assert_eq!(
            active_token(&reloaded, api_url).await.unwrap().as_deref(),
            Some("expired-token")
        );
        let config = reloaded.config.lock().await;
        assert_eq!(config.credential_api_url.as_deref(), Some(api_url));
        assert_eq!(config.authenticated_user_id.as_deref(), Some("user-a"));
    }

    #[tokio::test]
    async fn logout_keyring_failure_is_persisted_as_pending_cleanup_warning() {
        let directory = tempfile::tempdir().unwrap();
        let config_path = directory.path().join("settings.json");
        let api_url = "https://api.example.test";
        write_authenticated_settings(&config_path, api_url, "user-a");
        let (sender, _receiver) = mpsc::unbounded_channel();
        let credentials = Arc::new(DeleteFailureCredentialStore::default());
        credentials.set(api_url, "working-token").unwrap();
        let state = AppState::load(config_path.clone(), sender, credentials.clone());

        let error = logout_with_pre_rename_failure(&state, None)
            .await
            .unwrap_err();
        assert!(error.contains("signed out locally"));
        assert!(error.contains("CREDENTIAL_STORE_DELETE_FAILED"));
        assert!(!error.contains("FLYNN_KEYRING_SECRET_SENTINEL"));

        let persisted: Config = serde_json::from_slice(&fs::read(&config_path).unwrap()).unwrap();
        assert!(persisted.credential_api_url.is_none());
        assert!(persisted.authenticated_user_id.is_none());
        assert!(persisted
            .credential_cleanup_warning
            .as_deref()
            .is_some_and(|warning| warning.contains("CREDENTIAL_STORE_DELETE_FAILED")));
        let (sender, _receiver) = mpsc::unbounded_channel();
        let reloaded = AppState::load(config_path, sender, credentials.clone());
        assert!(active_token(&reloaded, api_url).await.unwrap().is_none());
        let status = get_status_for_state(&reloaded).await.unwrap();
        assert!(!status.authenticated);
        assert!(status
            .credential_cleanup_warning
            .as_deref()
            .is_some_and(|warning| warning.contains("CREDENTIAL_STORE_DELETE_FAILED")));
        assert_eq!(
            credentials.get(api_url).unwrap().as_deref(),
            Some("working-token")
        );
    }

    #[tokio::test]
    async fn stale_401_status_is_rejected_if_a_new_login_finishes_during_discovery() {
        let directory = tempfile::tempdir().unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let credentials = Arc::new(MockCredentialStore::default());
        credentials
            .set("https://api.example.test", "expired-token")
            .unwrap();
        let state = AppState::load(
            directory.path().join("settings.json"),
            sender,
            credentials.clone(),
        );
        {
            let mut config = state.config.lock().await;
            config.api_base_url = "https://api.example.test".into();
            config.credential_api_url = Some("https://api.example.test".into());
            config.authenticated_user_id = Some("old-user".into());
        }
        let expired_generation = state.session_generation.load(Ordering::SeqCst);

        // Simulate /me returning 401 and the status call entering installation
        // discovery after clearing the expired credential.
        let cleared_generation = clear_active_token_generation(
            &state,
            "https://api.example.test",
            "expired-token",
            expired_generation,
        )
        .await
        .expect("keyring deletion should succeed")
        .expect("the expired token should be cleared");

        // A browser login completes while the stale status call is discovering
        // files. It is serialized by the same mutation lock used by the final
        // signed-out status check.
        {
            let _mutation = state.session_mutation.lock().await;
            credentials
                .set("https://api.example.test", "new-token")
                .unwrap();
            let mut config = state.config.lock().await;
            config.credential_api_url = Some("https://api.example.test".into());
            config.authenticated_user_id = Some("new-user".into());
            state.invalidate_session();
        }

        let _mutation = state.session_mutation.lock().await;
        assert!(
            !signed_out_status_is_current(&state, cleared_generation, "https://api.example.test",)
                .await
        );
        assert_eq!(
            credentials
                .get("https://api.example.test")
                .unwrap()
                .as_deref(),
            Some("new-token")
        );
    }

    #[tokio::test]
    async fn stale_me_retry_is_cancelled_after_logout_instead_of_dispatching_again() {
        let directory = tempfile::tempdir().unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let state = Arc::new(AppState::load(
            directory.path().join("settings.json"),
            sender,
            Arc::new(MockCredentialStore::default()),
        ));
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();
        let session = SessionIdentity {
            generation: state.session_generation.load(Ordering::SeqCst),
            api_url: format!("http://{address}"),
            user_id: String::new(),
            token: "token-before-logout".into(),
            key: String::new(),
        };
        let request_state = state.clone();
        let request_session = session.clone();
        let request = tokio::spawn(async move {
            get_with_retry_fenced(
                &request_state,
                &format!("http://{address}/api/v1/me"),
                &request_session,
            )
            .await
        });
        let (mut socket, _) = tokio::time::timeout(Duration::from_secs(2), listener.accept())
            .await
            .unwrap()
            .unwrap();
        let mut request_bytes = [0; 1024];
        let _ = socket.read(&mut request_bytes).await.unwrap();
        socket
            .write_all(b"HTTP/1.1 503 Service Unavailable\r\nRetry-After: 30\r\nContent-Length: 0\r\nConnection: close\r\n\r\n")
            .await
            .unwrap();

        state.invalidate_session();
        let result = tokio::time::timeout(Duration::from_secs(2), request)
            .await
            .unwrap()
            .unwrap();
        assert!(result.unwrap_err().starts_with("SESSION_CHANGED:"));
        assert!(
            tokio::time::timeout(Duration::from_millis(50), listener.accept())
                .await
                .is_err(),
            "the stale /me retry must not be sent"
        );
    }

    async fn configure_active_test_session(
        state: &AppState,
        credentials: &MockCredentialStore,
        token: &str,
    ) -> SessionIdentity {
        let api_url = "https://api.example.test";
        credentials.set(api_url, token).unwrap();
        {
            let mut config = state.config.lock().await;
            config.api_base_url = api_url.into();
            config.credential_api_url = Some(api_url.into());
            config.authenticated_user_id = Some("user-a".into());
        }
        session_identity(
            state.session_generation.load(Ordering::SeqCst),
            api_url,
            "user-a",
            token,
        )
    }

    #[tokio::test]
    async fn signed_out_status_snapshot_is_rejected_after_a_new_login() {
        let directory = tempfile::tempdir().unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let credentials = Arc::new(MockCredentialStore::default());
        let state = AppState::load(
            directory.path().join("settings.json"),
            sender,
            credentials.clone(),
        );
        let snapshot_generation = state.session_generation.load(Ordering::SeqCst);
        let (api_url, credential_api_url, user_id) = {
            let config = state.config.lock().await;
            (
                config.api_base_url.clone(),
                config.credential_api_url.clone(),
                config.authenticated_user_id.clone(),
            )
        };
        assert!(
            status_snapshot_is_current(
                &state,
                snapshot_generation,
                &api_url,
                credential_api_url.as_deref(),
                user_id.as_deref(),
            )
            .await
        );

        {
            let _mutation = state.session_mutation.lock().await;
            state.invalidate_session();
            credentials.set(&api_url, "new-login-token").unwrap();
            let mut config = state.config.lock().await;
            config.credential_api_url = Some(api_url.clone());
            config.authenticated_user_id = Some("new-user".into());
        }

        assert!(
            !status_snapshot_is_current(
                &state,
                snapshot_generation,
                &api_url,
                credential_api_url.as_deref(),
                user_id.as_deref(),
            )
            .await
        );
    }

    #[tokio::test]
    async fn response_arriving_after_logout_cannot_commit_a_successful_hash() {
        let directory = tempfile::tempdir().unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let credentials = Arc::new(MockCredentialStore::default());
        let state = AppState::load(
            directory.path().join("settings.json"),
            sender,
            credentials.clone(),
        );
        let session = configure_active_test_session(&state, &credentials, "session-token").await;
        let file = directory.path().join("Flynn.lua");
        std::fs::write(&file, "FlynnDB = {}\n").unwrap();
        let completed = ActivityItem {
            id: "dump-1".into(),
            file: file.to_string_lossy().into_owned(),
            status: "completed".into(),
            message: "done".into(),
            at: now_iso(),
            characters_seen: Some(1),
            characters_updated: Some(1),
            partial: false,
            results: vec![],
        };

        state.invalidate_session(); // Simulate logout after the response body arrived.
        assert!(!commit_sync_result(&state, &completed, "response-hash", &file, &session).await);
        assert!(state.config.lock().await.successful_hashes.is_empty());
    }

    #[tokio::test]
    async fn same_identity_relogin_does_not_authorize_an_old_response_to_write_hashes() {
        let directory = tempfile::tempdir().unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let credentials = Arc::new(MockCredentialStore::default());
        let state = AppState::load(
            directory.path().join("settings.json"),
            sender,
            credentials.clone(),
        );
        let old_session = configure_active_test_session(&state, &credentials, "same-token").await;
        let file = directory.path().join("Flynn.lua");
        std::fs::write(&file, "FlynnDB = {}\n").unwrap();

        {
            let _mutation = state.session_mutation.lock().await;
            state.invalidate_session();
            credentials.set(&old_session.api_url, "same-token").unwrap();
            let mut config = state.config.lock().await;
            config.credential_api_url = Some(old_session.api_url.clone());
            config.authenticated_user_id = Some(old_session.user_id.clone());
        }
        let completed = ActivityItem {
            id: "dump-2".into(),
            file: file.to_string_lossy().into_owned(),
            status: "completed".into(),
            message: "done".into(),
            at: now_iso(),
            characters_seen: Some(1),
            characters_updated: Some(1),
            partial: false,
            results: vec![],
        };

        assert!(
            !commit_sync_result(&state, &completed, "old-response-hash", &file, &old_session).await
        );
        assert!(state.config.lock().await.successful_hashes.is_empty());
    }

    #[tokio::test]
    async fn logout_fences_a_post_that_has_not_been_dispatched() {
        let directory = tempfile::tempdir().unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let state = AppState::load(
            directory.path().join("settings.json"),
            sender,
            Arc::new(MockCredentialStore::default()),
        );
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();
        let session = SessionIdentity {
            generation: state.session_generation.load(Ordering::SeqCst),
            api_url: "https://api.example.test".into(),
            user_id: "user-a".into(),
            token: "old-token".into(),
            key: "old-key".into(),
        };

        state.invalidate_session();
        let result = post_upload_fenced(
            &state,
            &format!("http://{address}/api/v1/dumps"),
            &session,
            b"dump".to_vec(),
        )
        .await;
        assert!(result.unwrap_err().starts_with("SESSION_CHANGED:"));
        assert!(
            tokio::time::timeout(Duration::from_millis(50), listener.accept())
                .await
                .is_err(),
            "no old-token POST should reach the listener after logout"
        );
    }

    #[test]
    fn ambiguous_attempts_block_automatic_reposts_even_after_a_new_login() {
        let attempt = SubmissionAttempt {
            file: "/wow/Flynn.lua".into(),
            hash: "same-hash".into(),
            api_base_url: "https://api.example.test".into(),
            user_id: "user-a".into(),
            session_key: "prior-token-session".into(),
            state: "ambiguous".into(),
        };
        assert!(upload_blocked_by_attempt(
            &[attempt],
            "/wow/Flynn.lua",
            "same-hash",
            "https://api.example.test",
            "user-a",
            false,
            false,
        ));
        assert!(!upload_blocked_by_attempt(
            &[],
            "/wow/Flynn.lua",
            "different-hash",
            "https://api.example.test",
            "user-a",
            false,
            false,
        ));
    }

    #[tokio::test]
    async fn rejected_invalid_dump_is_not_reconciled_or_reposted() {
        let (_directory, state, mut events, file, hash, _) = reconciliation_fixture("failed").await;
        reconcile_files(&state, false).await;
        assert!(
            events.try_recv().is_err(),
            "rejected content must not be requeued"
        );
        let config = state.config.lock().await;
        assert!(upload_blocked_by_attempt(
            &config.submission_attempts,
            &file.to_string_lossy(),
            &hash,
            "https://api.example.test",
            "user-a",
            false,
            false,
        ));
        assert!(
            !upload_blocked_by_attempt(
                &config.submission_attempts,
                &file.to_string_lossy(),
                &hash,
                "https://api.example.test",
                "user-a",
                false,
                true,
            ),
            "Upload Now is the deliberate retry for a definitive rejection"
        );
    }

    #[tokio::test]
    async fn asynchronous_ingestion_rejection_is_persisted_and_not_requeued() {
        let (_directory, state, mut events, file, hash, credentials) =
            reconciliation_fixture("submitting").await;
        credentials
            .set("https://api.example.test", "session-token")
            .unwrap();
        let session = session_identity(
            state.session_generation.load(Ordering::SeqCst),
            "https://api.example.test",
            "user-a",
            "session-token",
        );
        let rejected = ActivityItem {
            id: "dump-invalid".into(),
            file: file.to_string_lossy().into_owned(),
            status: "failed".into(),
            message: "The dump failed validation.".into(),
            at: now_iso(),
            characters_seen: None,
            characters_updated: None,
            partial: false,
            results: Vec::new(),
        };

        assert!(commit_sync_result(&state, &rejected, &hash, &file, &session).await);
        reconcile_files(&state, false).await;
        assert!(events.try_recv().is_err());
        assert_eq!(
            state.config.lock().await.submission_attempts[0].state,
            "failed"
        );
    }

    #[tokio::test]
    async fn ambiguous_post_is_not_reconciled_or_automatically_reposted() {
        let (_directory, state, mut events, file, hash, credentials) =
            reconciliation_fixture("submitting").await;
        credentials
            .set("https://api.example.test", "session-token")
            .unwrap();
        let session = session_identity(
            state.session_generation.load(Ordering::SeqCst),
            "https://api.example.test",
            "user-a",
            "session-token",
        );
        let attempt = state.config.lock().await.submission_attempts[0].clone();
        assert!(mark_attempt_ambiguous(&state, &attempt, &session).await);
        reconcile_files(&state, false).await;
        assert!(
            events.try_recv().is_err(),
            "ambiguous content must not be requeued"
        );
        let config = state.config.lock().await;
        assert!(upload_blocked_by_attempt(
            &config.submission_attempts,
            &file.to_string_lossy(),
            &hash,
            "https://api.example.test",
            "user-a",
            false,
            false,
        ));
    }

    #[tokio::test]
    async fn crash_window_attempt_is_visible_actionable_after_restart_without_auto_post() {
        let (directory, state, _events, file, hash, credentials) =
            reconciliation_fixture("submitting").await;
        let config_path = directory.path().join("settings.json");
        state.save().await.unwrap();

        // A restart converts the durable pre-POST marker into an ambiguous
        // decision item. Startup reconciliation must display it, not enqueue
        // another POST automatically.
        let (sender, mut events) = mpsc::unbounded_channel();
        let restarted = AppState::load(config_path, sender, credentials.clone());
        let files = restarted.files().await;
        let config = restarted.config.lock().await.clone();
        let visible = visible_submission_attempts(
            &config,
            "https://api.example.test",
            Some("user-a"),
            &files,
            config.wow_root.as_deref(),
        );
        assert_eq!(visible.len(), 1);
        assert_eq!(visible[0].state, "ambiguous");
        assert!(visible[0].message.contains("may have accepted"));

        reconcile_files(&restarted, false).await;
        assert!(
            events.try_recv().is_err(),
            "restart must not auto-POST the ambiguous bytes"
        );
        assert!(upload_blocked_by_attempt(
            &config.submission_attempts,
            &file.to_string_lossy(),
            &hash,
            "https://api.example.test",
            "user-a",
            false,
            false,
        ));
        assert!(
            !upload_blocked_by_attempt(
                &config.submission_attempts,
                &file.to_string_lossy(),
                &hash,
                "https://api.example.test",
                "user-a",
                true,
                false,
            ),
            "the persisted ambiguous state is eligible for the explicit retry action"
        );
    }

    #[tokio::test]
    async fn prepost_failure_is_persisted_and_identical_content_is_not_reconciled() {
        let directory = tempfile::tempdir().unwrap();
        let root = directory.path().join("World of Warcraft");
        let file = root
            .join("_retail_")
            .join("WTF/Account/test-account/SavedVariables/Flynn.lua");
        fs::create_dir_all(file.parent().unwrap()).unwrap();
        fs::write(&file, "unchanged file that could not settle").unwrap();
        let root = fs::canonicalize(root).unwrap();
        let file = fs::canonicalize(file).unwrap();
        let (sender, mut events) = mpsc::unbounded_channel();
        let credentials = Arc::new(MockCredentialStore::default());
        let state = AppState::load(
            directory.path().join("settings.json"),
            sender,
            credentials.clone(),
        );
        credentials
            .set("https://api.example.test", "session-token")
            .unwrap();
        {
            let mut config = state.config.lock().await;
            config.wow_root = Some(root.clone());
            config.api_base_url = "https://api.example.test".into();
            config.credential_api_url = Some("https://api.example.test".into());
            config.authenticated_user_id = Some("user-a".into());
        }
        let session = session_identity(
            state.session_generation.load(Ordering::SeqCst),
            "https://api.example.test",
            "user-a",
            "session-token",
        );
        persist_prepost_failure(&state, &file, &root, &session)
            .await
            .unwrap();
        let (_, hash) = read_file_with_hash_under_root(&root, &file).unwrap();

        reconcile_files(&state, false).await;
        assert!(
            events.try_recv().is_err(),
            "the failed identical file must not be requeued"
        );
        let config = state.config.lock().await;
        assert!(upload_blocked_by_attempt(
            &config.submission_attempts,
            &file.to_string_lossy(),
            &hash,
            "https://api.example.test",
            "user-a",
            false,
            false,
        ));
        assert!(
            !upload_blocked_by_attempt(
                &config.submission_attempts,
                &file.to_string_lossy(),
                &hash,
                "https://api.example.test",
                "user-a",
                false,
                true,
            ),
            "manual retry may deliberately retry the same content"
        );
    }

    #[tokio::test]
    async fn changed_content_is_reconciled_after_a_previous_rejection() {
        let (_directory, state, mut events, file, old_hash, _) =
            reconciliation_fixture("failed").await;
        fs::write(&file, "FlynnDB = { value = 2 }\n").unwrap();
        let (_, new_hash) = read_file_with_hash_under_root(
            state.config.lock().await.wow_root.as_deref().unwrap(),
            &file,
        )
        .unwrap();
        assert_ne!(old_hash, new_hash);
        reconcile_files(&state, false).await;
        assert_eq!(events.try_recv().unwrap(), file);
    }

    #[tokio::test]
    async fn stale_ambiguous_attempt_is_hidden_after_changed_content_sync_but_retained_for_duplicate_risk(
    ) {
        let (_directory, state, _events, file, old_hash, credentials) =
            reconciliation_fixture("ambiguous").await;
        credentials
            .set("https://api.example.test", "session-token")
            .unwrap();
        let session = session_identity(
            state.session_generation.load(Ordering::SeqCst),
            "https://api.example.test",
            "user-a",
            "session-token",
        );
        fs::write(&file, "FlynnDB = { value = 2 }\n").unwrap();
        let root = state.config.lock().await.wow_root.clone().unwrap();
        let (_, new_hash) = read_file_with_hash_under_root(&root, &file).unwrap();
        let success = ActivityItem {
            id: "new-dump".into(),
            file: file.to_string_lossy().into_owned(),
            status: "completed".into(),
            message: "Dump ingested successfully.".into(),
            at: now_iso(),
            characters_seen: Some(1),
            characters_updated: Some(1),
            partial: false,
            results: Vec::new(),
        };
        assert!(commit_sync_result(&state, &success, &new_hash, &file, &session).await);

        let config = state.config.lock().await.clone();
        let visible = visible_submission_attempts(
            &config,
            "https://api.example.test",
            Some("user-a"),
            &state.files().await,
            Some(&root),
        );
        assert!(
            visible.is_empty(),
            "the old hash is not retryable for current content"
        );
        assert!(config
            .submission_attempts
            .iter()
            .any(|attempt| attempt.hash == old_hash));
        assert!(
            upload_blocked_by_attempt(
                &config.submission_attempts,
                &file.to_string_lossy(),
                &old_hash,
                "https://api.example.test",
                "user-a",
                false,
                false,
            ),
            "historical ambiguous evidence remains available if old bytes return"
        );
        assert!(!upload_blocked_by_attempt(
            &config.submission_attempts,
            &file.to_string_lossy(),
            &new_hash,
            "https://api.example.test",
            "user-a",
            false,
            false,
        ));
    }

    #[tokio::test]
    async fn current_failed_attempt_is_visible_and_retry_eligibility_matches_its_ui_action() {
        let (_directory, state, _events, file, hash, _) = reconciliation_fixture("failed").await;
        let config = state.config.lock().await.clone();
        let files = state.files().await;
        let visible = visible_submission_attempts(
            &config,
            "https://api.example.test",
            Some("user-a"),
            &files,
            config.wow_root.as_deref(),
        );
        assert_eq!(visible.len(), 1);
        assert_eq!(visible[0].state, "failed");
        assert!(upload_blocked_by_attempt(
            &config.submission_attempts,
            &file.to_string_lossy(),
            &hash,
            "https://api.example.test",
            "user-a",
            false,
            false,
        ));
        assert!(
            !upload_blocked_by_attempt(
                &config.submission_attempts,
                &file.to_string_lossy(),
                &hash,
                "https://api.example.test",
                "user-a",
                false,
                true,
            ),
            "the failed-attempt UI action (Upload Now) is eligible"
        );
    }

    #[tokio::test]
    async fn interrupted_post_is_cancelled_and_never_uses_the_old_session_again() {
        let directory = tempfile::tempdir().unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let state = Arc::new(AppState::load(
            directory.path().join("settings.json"),
            sender,
            Arc::new(MockCredentialStore::default()),
        ));
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();
        let session = SessionIdentity {
            generation: state.session_generation.load(Ordering::SeqCst),
            api_url: format!("http://{address}"),
            user_id: "user-a".into(),
            token: "old-token".into(),
            key: "old-key".into(),
        };
        let request_state = state.clone();
        let request_session = session.clone();
        let request = tokio::spawn(async move {
            post_upload_fenced(
                &request_state,
                &format!("http://{address}/api/v1/dumps"),
                &request_session,
                b"FlynnDB = {}".to_vec(),
            )
            .await
        });
        let (_socket, _) = tokio::time::timeout(Duration::from_secs(2), listener.accept())
            .await
            .unwrap()
            .unwrap();
        state.invalidate_session();
        let result = tokio::time::timeout(Duration::from_secs(2), request)
            .await
            .unwrap()
            .unwrap();
        assert!(result.unwrap_err().starts_with("SESSION_CHANGED:"));
    }

    #[tokio::test]
    async fn pending_dump_ids_and_session_fences_survive_a_config_reload() {
        let directory = tempfile::tempdir().unwrap();
        let config_path = directory.path().join("settings.json");
        let (sender, _receiver) = mpsc::unbounded_channel();
        let credentials = Arc::new(MockCredentialStore::default());
        let state = AppState::load(config_path.clone(), sender, credentials.clone());
        state.config.lock().await.pending_dumps.push(PendingDump {
            dump_id: "dump-123".into(),
            file: "/wow/SavedVariables/Flynn.lua".into(),
            hash: "sha256-value".into(),
            api_base_url: "https://api.example.test".into(),
            user_id: "user-a".into(),
        });
        state
            .config
            .lock()
            .await
            .submission_attempts
            .push(SubmissionAttempt {
                file: "/wow/SavedVariables/Flynn.lua".into(),
                hash: "attempt-hash".into(),
                api_base_url: "https://api.example.test".into(),
                user_id: "user-a".into(),
                session_key: "token-fingerprint".into(),
                state: "submitting".into(),
            });
        state.save().await.unwrap();

        let (sender, _receiver) = mpsc::unbounded_channel();
        let restarted = AppState::load(config_path, sender, credentials);
        let config = restarted.config.lock().await;
        assert_eq!(config.pending_dumps.len(), 1);
        assert_eq!(config.pending_dumps[0].dump_id, "dump-123");
        assert_eq!(
            config.pending_dumps[0].api_base_url,
            "https://api.example.test"
        );
        assert_eq!(config.pending_dumps[0].user_id, "user-a");
        assert_eq!(config.submission_attempts[0].state, "ambiguous");
    }

    #[tokio::test]
    async fn persisted_pending_dump_resumes_by_id_after_source_file_is_deleted() {
        let directory = tempfile::tempdir().unwrap();
        let config_path = directory.path().join("settings.json");
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();
        let api_url = format!("http://{address}");
        let credentials = Arc::new(MockCredentialStore::default());
        credentials.set(&api_url, "session-token").unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let state = AppState::load(config_path.clone(), sender, credentials.clone());
        {
            let mut config = state.config.lock().await;
            config.api_base_url = api_url.clone();
            config.credential_api_url = Some(api_url.clone());
            config.authenticated_user_id = Some("user-a".into());
            config.pending_dumps.push(PendingDump {
                dump_id: "accepted-dump".into(),
                file: directory
                    .path()
                    .join("removed/Flynn.lua")
                    .to_string_lossy()
                    .into_owned(),
                hash: "accepted-content-hash".into(),
                api_base_url: api_url.clone(),
                user_id: "user-a".into(),
            });
        }
        state.save().await.unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let restarted = AppState::load(config_path, sender, credentials);
        let app = mock_app_handle();

        let server = tokio::spawn(async move {
            let mut paths = Vec::new();
            for _ in 0..2 {
                let (mut socket, _) = listener.accept().await.unwrap();
                let request = read_complete_http_request(&mut socket).await;
                let request_line = request.lines().next().unwrap_or_default().to_string();
                paths.push(request_line.clone());
                let body = if request_line.contains("/api/v1/me") {
                    r#"{"data":{"id":"user-a","name":"Tester","email":"test@example.test"}}"#
                } else {
                    r#"{"data":{"status":"completed","charactersSeen":1,"charactersUpdated":1,"results":[]}}"#
                };
                let response = format!(
                    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
                    body.len(), body
                );
                socket.write_all(response.as_bytes()).await.unwrap();
            }
            paths
        });

        resume_pending_dumps(&app, &restarted).await;
        let paths = server.await.unwrap();
        assert!(paths[0].contains("/api/v1/me"));
        assert!(paths[1].contains("/api/v1/dumps/accepted-dump"));
        assert!(paths.iter().all(|path| !path.contains("POST")));
        let config = restarted.config.lock().await;
        assert!(
            config.pending_dumps.is_empty(),
            "terminal status clears the saved ID"
        );
        assert_eq!(config.activity[0].status, "completed");
    }

    #[tokio::test]
    async fn persisted_ambiguous_retry_posts_only_through_confirmed_retry_command() {
        let directory = tempfile::tempdir().unwrap();
        let root = directory.path().join("World of Warcraft");
        let file = root
            .join("_retail_")
            .join("WTF/Account/test-account/SavedVariables/Flynn.lua");
        fs::create_dir_all(file.parent().unwrap()).unwrap();
        fs::write(&file, "FlynnDB = { value = 1 }\n").unwrap();
        let root = fs::canonicalize(root).unwrap();
        let file = fs::canonicalize(file).unwrap();
        let (_, hash) = read_file_with_hash_under_root(&root, &file).unwrap();
        let config_path = directory.path().join("settings.json");
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();
        let api_url = format!("http://{address}");
        let credentials = Arc::new(MockCredentialStore::default());
        credentials.set(&api_url, "session-token").unwrap();
        let (sender, _events) = mpsc::unbounded_channel();
        let state = AppState::load(config_path.clone(), sender, credentials.clone());
        {
            let mut config = state.config.lock().await;
            config.wow_root = Some(root);
            config.api_base_url = api_url.clone();
            config.credential_api_url = Some(api_url.clone());
            config.authenticated_user_id = Some("user-a".into());
            config.submission_attempts.push(SubmissionAttempt {
                file: file.to_string_lossy().into_owned(),
                hash,
                api_base_url: api_url.clone(),
                user_id: "user-a".into(),
                session_key: "previous-session".into(),
                state: "ambiguous".into(),
            });
        }
        state.save().await.unwrap();

        let (sender, mut restarted_events) = mpsc::unbounded_channel();
        let restarted = AppState::load(config_path, sender, credentials);
        reconcile_files(&restarted, false).await;
        assert!(
            restarted_events.try_recv().is_err(),
            "restart reconciliation must not queue a POST"
        );
        let app = mock_app_handle();
        let refusal = retry_ambiguous_upload_inner(
            file.to_string_lossy().into_owned(),
            false,
            &app,
            &restarted,
        )
        .await
        .unwrap_err();
        assert!(refusal.contains("Explicit confirmation is required"));
        assert!(
            tokio::time::timeout(Duration::from_millis(50), listener.accept())
                .await
                .is_err(),
            "no API request may be dispatched before explicit confirmation"
        );

        let server = tokio::spawn(async move {
            let mut post_count = 0;
            for _ in 0..3 {
                let (mut socket, _) = listener.accept().await.unwrap();
                let request = read_complete_http_request(&mut socket).await;
                let request_line = request.lines().next().unwrap_or_default().to_string();
                let (status, body) = if request_line.contains("/api/v1/me") {
                    (
                        "200 OK",
                        r#"{"data":{"id":"user-a","name":"Tester","email":"test@example.test"}}"#,
                    )
                } else if request_line.starts_with("POST /api/v1/dumps ") {
                    post_count += 1;
                    (
                        "202 Accepted",
                        r#"{"data":{"dumpId":"retry-dump","status":"queued"}}"#,
                    )
                } else {
                    (
                        "200 OK",
                        r#"{"data":{"status":"completed","charactersSeen":1,"charactersUpdated":1,"results":[]}}"#,
                    )
                };
                let response = format!(
                    "HTTP/1.1 {status}\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
                    body.len(), body
                );
                socket.write_all(response.as_bytes()).await.unwrap();
            }
            post_count
        });
        let outcome = retry_ambiguous_upload_inner(
            file.to_string_lossy().into_owned(),
            true,
            &app,
            &restarted,
        )
        .await
        .unwrap();
        assert_eq!(outcome.status, "completed");
        assert_eq!(
            server.await.unwrap(),
            1,
            "confirmed retry performs one real POST"
        );
    }

    #[test]
    fn successful_hashes_are_scoped_by_origin_user_and_canonical_source() {
        let directory = tempfile::tempdir().unwrap();
        let source = directory.path().join("Flynn.lua");
        std::fs::write(&source, "FlynnDB = {}\n").unwrap();
        let key = sync_hash_key("https://EXAMPLE.test:443/api", "user-a", &source);
        assert_eq!(
            key,
            sync_hash_key("https://example.test/api", "user-a", &source)
        );
        assert_ne!(
            key,
            sync_hash_key("https://other.test/api", "user-a", &source)
        );
        assert_ne!(
            key,
            sync_hash_key("https://example.test/api", "user-b", &source)
        );
        assert_ne!(
            key,
            sync_hash_key(
                "https://example.test/api",
                "user-a",
                &directory.path().join("other.lua")
            )
        );

        let mut config = Config {
            credential_api_url: Some("https://example.test".into()),
            authenticated_user_id: Some("user-a".into()),
            ..Config::default()
        };
        config.successful_hashes.insert(key, "sha".into());
        bind_authenticated_user(&mut config, "https://example.test", "user-b");
        assert!(config.successful_hashes.is_empty());
    }

    #[test]
    fn retry_after_is_honored_and_backoff_is_bounded() {
        assert_eq!(retry_delay(Some("7"), 0), Duration::from_secs(7));
        assert_eq!(retry_delay(Some("999999"), 0), Duration::from_secs(300));
        assert_eq!(retry_delay(None, 0), Duration::from_secs(1));
        assert_eq!(retry_delay(None, 99), Duration::from_secs(30));
        assert!(is_retryable_status(StatusCode::TOO_MANY_REQUESTS));
        assert!(!is_retryable_status(StatusCode::BAD_REQUEST));
    }

    #[tokio::test]
    async fn rate_limited_upload_retries_are_bounded_and_honor_retry_after() {
        let directory = tempfile::tempdir().unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let state = Arc::new(AppState::load(
            directory.path().join("settings.json"),
            sender,
            Arc::new(MockCredentialStore::default()),
        ));
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();
        let server = tokio::spawn(async move {
            let mut requests = 0;
            for _ in 0..3 {
                let (mut socket, _) = listener.accept().await.unwrap();
                let mut bytes = Vec::new();
                let mut buffer = [0; 4096];
                let header_end = loop {
                    let read = socket.read(&mut buffer).await.unwrap();
                    assert_ne!(read, 0);
                    bytes.extend_from_slice(&buffer[..read]);
                    if let Some(position) = bytes.windows(4).position(|part| part == b"\r\n\r\n") {
                        break position + 4;
                    }
                };
                let headers = String::from_utf8_lossy(&bytes[..header_end]);
                let content_length = headers
                    .lines()
                    .find_map(|line| {
                        line.to_ascii_lowercase()
                            .strip_prefix("content-length:")
                            .and_then(|value| value.trim().parse::<usize>().ok())
                    })
                    .unwrap_or(0);
                while bytes.len() < header_end + content_length {
                    let read = socket.read(&mut buffer).await.unwrap();
                    assert_ne!(read, 0);
                    bytes.extend_from_slice(&buffer[..read]);
                }
                requests += 1;
                socket
                    .write_all(b"HTTP/1.1 429 Too Many Requests\r\nRetry-After: 0\r\nContent-Length: 0\r\nConnection: close\r\n\r\n")
                    .await
                    .unwrap();
            }
            requests
        });
        let session = SessionIdentity {
            generation: state.session_generation.load(Ordering::SeqCst),
            api_url: format!("http://{address}"),
            user_id: "user-a".into(),
            token: "token".into(),
            key: "session-key".into(),
        };

        let response = post_upload_fenced(
            &state,
            &format!("http://{address}/api/v1/dumps"),
            &session,
            b"FlynnDB = {}".to_vec(),
        )
        .await
        .unwrap();
        assert_eq!(response.status(), StatusCode::TOO_MANY_REQUESTS);
        assert_eq!(
            server.await.unwrap(),
            3,
            "POST retry count must stay bounded"
        );
    }

    async fn read_complete_http_request(socket: &mut tokio::net::TcpStream) -> String {
        let mut bytes = Vec::new();
        let mut buffer = [0; 4096];
        let header_end = loop {
            let read = socket.read(&mut buffer).await.unwrap();
            assert_ne!(read, 0);
            bytes.extend_from_slice(&buffer[..read]);
            if let Some(position) = bytes.windows(4).position(|part| part == b"\r\n\r\n") {
                break position + 4;
            }
        };
        let headers = String::from_utf8_lossy(&bytes[..header_end]);
        let content_length = headers
            .lines()
            .find_map(|line| {
                line.to_ascii_lowercase()
                    .strip_prefix("content-length:")
                    .and_then(|value| value.trim().parse::<usize>().ok())
            })
            .unwrap_or(0);
        while bytes.len() < header_end + content_length {
            let read = socket.read(&mut buffer).await.unwrap();
            assert_ne!(read, 0);
            bytes.extend_from_slice(&buffer[..read]);
        }
        String::from_utf8_lossy(&bytes[..header_end]).into_owned()
    }

    #[tokio::test]
    async fn rate_limited_upload_succeeds_after_429() {
        let directory = tempfile::tempdir().unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let state = AppState::load(
            directory.path().join("settings.json"),
            sender,
            Arc::new(MockCredentialStore::default()),
        );
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();
        let server = tokio::spawn(async move {
            for attempt in 0..2 {
                let (mut socket, _) = listener.accept().await.unwrap();
                read_complete_http_request(&mut socket).await;
                if attempt == 0 {
                    socket.write_all(b"HTTP/1.1 429 Too Many Requests\r\nRetry-After: 0\r\nContent-Length: 0\r\nConnection: close\r\n\r\n").await.unwrap();
                } else {
                    let body = r#"{"data":{"dumpId":"dump-after-429","status":"queued"}}"#;
                    socket.write_all(format!("HTTP/1.1 202 Accepted\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}", body.len(), body).as_bytes()).await.unwrap();
                }
            }
        });
        let session = SessionIdentity {
            generation: state.session_generation.load(Ordering::SeqCst),
            api_url: format!("http://{address}"),
            user_id: "user-a".into(),
            token: "token".into(),
            key: "session-key".into(),
        };

        let response = post_upload_fenced(
            &state,
            &format!("http://{address}/api/v1/dumps"),
            &session,
            b"FlynnDB = {}".to_vec(),
        )
        .await
        .unwrap();
        assert_eq!(response.status(), StatusCode::ACCEPTED);
        server.await.unwrap();
    }

    #[tokio::test]
    async fn logout_during_upload_rate_limit_backoff_cancels_retry() {
        let directory = tempfile::tempdir().unwrap();
        let (sender, _receiver) = mpsc::unbounded_channel();
        let state = Arc::new(AppState::load(
            directory.path().join("settings.json"),
            sender,
            Arc::new(MockCredentialStore::default()),
        ));
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();
        let (accepted_tx, accepted_rx) = tokio::sync::oneshot::channel();
        let server = tokio::spawn(async move {
            let (mut socket, _) = listener.accept().await.unwrap();
            read_complete_http_request(&mut socket).await;
            socket.write_all(b"HTTP/1.1 429 Too Many Requests\r\nRetry-After: 30\r\nContent-Length: 0\r\nConnection: close\r\n\r\n").await.unwrap();
            let _ = accepted_tx.send(());
            // A second accept would indicate a forbidden retry after logout.
            tokio::time::timeout(Duration::from_millis(150), listener.accept())
                .await
                .is_ok()
        });
        let session = SessionIdentity {
            generation: state.session_generation.load(Ordering::SeqCst),
            api_url: format!("http://{address}"),
            user_id: "user-a".into(),
            token: "token".into(),
            key: "session-key".into(),
        };
        let request_state = state.clone();
        let request = tokio::spawn(async move {
            post_upload_fenced(
                &request_state,
                &format!("http://{address}/api/v1/dumps"),
                &session,
                b"FlynnDB = {}".to_vec(),
            )
            .await
        });
        accepted_rx.await.unwrap();
        state.invalidate_session();

        let result = tokio::time::timeout(Duration::from_secs(2), request)
            .await
            .unwrap()
            .unwrap();
        assert!(result.unwrap_err().starts_with("SESSION_CHANGED:"));
        assert!(
            !server.await.unwrap(),
            "no second POST should be dispatched after logout"
        );
    }

    #[test]
    fn errors_show_api_message_not_response_dump() {
        let secret = "token-never-show-this";
        let value = json!({"error":{"message":"Please retry."}, "debug":secret});
        assert_eq!(
            error_message(&value, StatusCode::BAD_REQUEST),
            "Please retry."
        );
        assert!(!error_message(&value, StatusCode::BAD_REQUEST).contains(secret));
        let leaked = json!({"error":{"message":format!("Rejected {secret}.")}});
        assert_eq!(
            api_error_message(&leaked, StatusCode::BAD_REQUEST, secret),
            "Rejected [redacted]."
        );
    }

    #[test]
    fn only_success_without_partial_results_is_dedupe_eligible() {
        let success = ActivityItem {
            id: "1".into(),
            file: "f".into(),
            status: "completed".into(),
            message: "ok".into(),
            at: "now".into(),
            characters_seen: Some(1),
            characters_updated: Some(1),
            partial: false,
            results: vec![],
        };
        let partial = ActivityItem {
            partial: true,
            ..success.clone()
        };
        assert!(!success.partial);
        assert!(partial.partial);
    }

    #[test]
    fn skips_only_when_current_sha_matches_a_successfully_ingested_hash() {
        assert!(should_skip_upload(Some("sha256-a"), "sha256-a"));
        assert!(!should_skip_upload(Some("sha256-a"), "sha256-b"));
        assert!(!should_skip_upload(None, "sha256-a"));
    }

    #[test]
    fn queued_upload_is_not_mistaken_for_a_terminal_success() {
        assert!(valid_upload_receipt(StatusCode::ACCEPTED, "queued"));
        assert!(valid_upload_receipt(StatusCode::OK, "processing"));
        assert!(!valid_upload_receipt(StatusCode::OK, "completed"));
        assert!(!valid_upload_receipt(
            StatusCode::UNPROCESSABLE_ENTITY,
            "queued"
        ));
        assert!(!valid_upload_receipt(StatusCode::ACCEPTED, "failed"));
    }

    #[test]
    fn claimable_imports_are_success_but_stale_or_conflicting_records_are_partial() {
        let claimable = CharacterResult {
            status: Some("claimable".into()),
            ..CharacterResult::default()
        };
        let stale = CharacterResult {
            status: Some("stale_snapshot".into()),
            ..CharacterResult::default()
        };
        let ownership_conflict = CharacterResult {
            status: Some("ownership_conflict".into()),
            ..CharacterResult::default()
        };
        assert!(!has_partial_results(&[claimable]));
        assert!(has_partial_results(&[stale]));
        assert!(has_partial_results(&[ownership_conflict]));
    }

    #[test]
    fn debounce_requires_multiple_identical_file_samples() {
        let first = Some((128, 1_000));
        assert_eq!(update_stability(None, first, 0), 0);
        assert_eq!(update_stability(first, first, 0), 1);
        assert_eq!(update_stability(first, first, 1), 2);
        assert_eq!(update_stability(first, Some((256, 2_000)), 2), 0);
        assert_eq!(update_stability(first, None, 2), 0);
    }

    #[test]
    fn credentials_have_no_settings_or_activity_serialization_field() {
        let config = Config::default();
        let serialized = serde_json::to_string(&config).unwrap();
        assert!(!serialized.to_ascii_lowercase().contains("token"));
        assert!(!serialized.contains("accessToken"));
    }
}
