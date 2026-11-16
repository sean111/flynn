use serde::{Deserialize, Serialize};
use std::{
    collections::BTreeSet,
    fs,
    path::{Path, PathBuf},
};

pub const FLAVORS: [&str; 4] = ["_retail_", "_classic_era_", "_classic_", "_forever_"];
pub const MAX_UPLOAD_BYTES: u64 = 10 * 1024 * 1024;

#[derive(Clone, Debug, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct DumpFile {
    pub path: String,
    pub flavor: String,
    pub account: String,
    pub size_bytes: u64,
    pub modified_at: Option<String>,
    pub enabled: bool,
}

pub fn scope_key(flavor: &str, account: &str) -> String {
    format!("{flavor}/{account}")
}

pub fn detect_installations() -> Vec<PathBuf> {
    let mut candidates = Vec::new();
    #[cfg(target_os = "macos")]
    {
        candidates.push(PathBuf::from("/Applications/World of Warcraft"));
        if let Some(home) = dirs_home() {
            candidates.push(home.join("Applications/World of Warcraft"));
        }
    }
    #[cfg(target_os = "windows")]
    {
        for root in [
            std::env::var_os("ProgramFiles(x86)"),
            std::env::var_os("ProgramFiles"),
        ]
        .into_iter()
        .flatten()
        {
            candidates.push(PathBuf::from(root).join("World of Warcraft"));
        }
    }
    #[cfg(target_os = "linux")]
    {
        if let Some(home) = dirs_home() {
            candidates.extend([
                home.join(".wine/drive_c/Program Files (x86)/World of Warcraft"),
                home.join(".wine/drive_c/Program Files/World of Warcraft"),
                home.join(".local/share/Steam/steamapps/compatdata/"),
            ]);
        }
    }
    candidates
        .into_iter()
        .filter(|path| is_wow_root(path))
        .collect()
}

fn dirs_home() -> Option<PathBuf> {
    std::env::var_os("HOME")
        .or_else(|| std::env::var_os("USERPROFILE"))
        .map(PathBuf::from)
}

pub fn is_wow_root(path: &Path) -> bool {
    FLAVORS.iter().any(|flavor| path.join(flavor).is_dir())
}

pub fn discover_files(root: &Path, disabled_scopes: &BTreeSet<String>) -> Vec<DumpFile> {
    let Ok(root) = fs::canonicalize(root) else {
        return Vec::new();
    };
    let mut files = Vec::new();
    for flavor in FLAVORS {
        let account_root = root.join(flavor).join("WTF").join("Account");
        let Ok(canonical_account_root) = fs::canonicalize(&account_root) else {
            continue;
        };
        if !canonical_account_root.starts_with(&root) || !canonical_account_root.is_dir() {
            continue;
        }
        let Ok(accounts) = fs::read_dir(account_root) else {
            continue;
        };
        for account_entry in accounts.flatten() {
            let account_path = account_entry.path();
            let Ok(canonical_account) = fs::canonicalize(&account_path) else {
                continue;
            };
            if !canonical_account.starts_with(&root) || !canonical_account.is_dir() {
                continue;
            }
            let account = account_entry.file_name().to_string_lossy().into_owned();
            let candidate = account_entry.path().join("SavedVariables/Flynn.lua");
            let Ok(canonical_candidate) = fs::canonicalize(&candidate) else {
                continue;
            };
            if !canonical_candidate.starts_with(&root) {
                continue;
            }
            let Some(canonical_flavor) = canonical_candidate
                .strip_prefix(&root)
                .ok()
                .and_then(|path| path.components().next())
            else {
                continue;
            };
            if !matches!(canonical_flavor, std::path::Component::Normal(name) if name == std::ffi::OsStr::new(flavor))
            {
                continue;
            }
            let Ok(metadata) = fs::metadata(&candidate) else {
                continue;
            };
            if !metadata.is_file() {
                continue;
            }
            let modified_at = metadata
                .modified()
                .ok()
                .and_then(|time| time.duration_since(std::time::UNIX_EPOCH).ok())
                .map(|duration| duration.as_secs().to_string());
            let scope = scope_key(flavor, &account);
            files.push(DumpFile {
                path: canonical_candidate.to_string_lossy().into_owned(),
                flavor: flavor.trim_matches('_').to_string(),
                account: account.clone(),
                size_bytes: metadata.len(),
                modified_at,
                enabled: !disabled_scopes.contains(&scope),
            });
        }
    }
    files.sort_by(|left, right| {
        left.flavor
            .cmp(&right.flavor)
            .then(left.account.cmp(&right.account))
    });
    files
}

/// Read a discovered dump by walking from an opened WoW-root directory handle.
/// Every path component, including the final file, is opened relative to its
/// parent with no-follow semantics, so swapping a parent for a symlink between
/// discovery and upload cannot redirect the read outside the selected root.
pub fn read_file_with_hash_under_root(
    root: &Path,
    path: &Path,
) -> Result<(Vec<u8>, String), String> {
    read_file_with_hash_under_root_after_root_open(root, path, || {})
}

fn read_file_with_hash_under_root_after_root_open(
    root: &Path,
    path: &Path,
    after_root_open: impl FnOnce(),
) -> Result<(Vec<u8>, String), String> {
    use sha2::{Digest, Sha256};
    // The configured root is canonicalized when selected. Do not canonicalize
    // it again here: doing so would erase evidence if an ancestor were swapped
    // for a symlink/junction after discovery, before the handle-relative walk.
    if !root.is_absolute() {
        return Err("Could not safely open WoW folder: expected an absolute path.".into());
    }
    let relative = path
        .strip_prefix(root)
        .map_err(|_| "Flynn.lua is no longer inside the selected WoW folder.")?;
    let file = open_relative_without_links(root, relative, after_root_open)?;
    let metadata = file
        .metadata()
        .map_err(|error| format!("Could not inspect Flynn.lua: {error}"))?;
    if !metadata.is_file() {
        return Err("Flynn.lua is not a regular file.".into());
    }
    if metadata.len() > MAX_UPLOAD_BYTES {
        return Err("Flynn.lua is larger than the 10 MiB upload limit.".into());
    }
    let content = read_bounded(file)?;
    let digest = Sha256::digest(&content);
    let hash = digest.iter().map(|byte| format!("{byte:02x}")).collect();
    Ok((content, hash))
}

#[cfg(any(target_os = "macos", target_os = "linux"))]
fn open_relative_without_links(
    root: &Path,
    relative: &Path,
    after_root_open: impl FnOnce(),
) -> Result<fs::File, String> {
    fn open_at(
        parent: &fs::File,
        name: &std::ffi::OsStr,
        directory: bool,
    ) -> std::io::Result<fs::File> {
        use std::{
            ffi::CString,
            os::{
                fd::{AsRawFd, FromRawFd},
                unix::ffi::OsStrExt,
            },
        };
        let name = CString::new(name.as_bytes()).map_err(|_| {
            std::io::Error::new(std::io::ErrorKind::InvalidInput, "path contains a NUL byte")
        })?;
        let mut flags = libc::O_RDONLY | libc::O_NOFOLLOW | libc::O_CLOEXEC;
        if directory {
            flags |= libc::O_DIRECTORY;
        }
        // SAFETY: parent is an open directory; name is a NUL-terminated single
        // component and flags request no-follow descriptor-relative access.
        let fd = unsafe { libc::openat(parent.as_raw_fd(), name.as_ptr(), flags) };
        if fd < 0 {
            return Err(std::io::Error::last_os_error());
        }
        // SAFETY: openat returned a new owned descriptor.
        Ok(unsafe { fs::File::from_raw_fd(fd) })
    }

    fn open_absolute_directory(path: &Path) -> std::io::Result<fs::File> {
        use std::os::unix::fs::OpenOptionsExt;
        if !path.is_absolute() {
            return Err(std::io::Error::new(
                std::io::ErrorKind::InvalidInput,
                "WoW folder must be an absolute path",
            ));
        }
        let mut current = fs::OpenOptions::new()
            .read(true)
            .custom_flags(libc::O_DIRECTORY | libc::O_NOFOLLOW | libc::O_CLOEXEC)
            .open("/")?;
        for component in path.components() {
            match component {
                std::path::Component::RootDir => {}
                std::path::Component::Normal(name) => current = open_at(&current, name, true)?,
                _ => {
                    return Err(std::io::Error::new(
                        std::io::ErrorKind::InvalidInput,
                        "invalid WoW folder path",
                    ));
                }
            }
        }
        Ok(current)
    }

    // Validate the known discovery layout before touching any descendant.
    let components: Vec<_> = relative.components().collect();
    if components.len() != 6
        || !matches!(components[0], std::path::Component::Normal(flavor) if flavor.to_str().is_some_and(|flavor| FLAVORS.contains(&flavor)))
        || components[1] != std::path::Component::Normal(std::ffi::OsStr::new("WTF"))
        || components[2] != std::path::Component::Normal(std::ffi::OsStr::new("Account"))
        || !matches!(components[3], std::path::Component::Normal(_))
        || components[4] != std::path::Component::Normal(std::ffi::OsStr::new("SavedVariables"))
        || components[5] != std::path::Component::Normal(std::ffi::OsStr::new("Flynn.lua"))
    {
        return Err(
            "Flynn.lua path is not a supported source under the selected WoW folder.".into(),
        );
    }

    let root_dir = open_absolute_directory(root)
        .map_err(|error| format!("Could not safely open WoW folder: {error}"))?;
    after_root_open();
    let mut parent = root_dir;
    for component in components.iter().take(components.len() - 1) {
        let std::path::Component::Normal(name) = component else {
            return Err("Flynn.lua path contains an unsafe component.".into());
        };
        parent = open_at(&parent, name, true).map_err(|error| {
            format!("Could not safely traverse Flynn.lua path without following links: {error}")
        })?;
    }
    let std::path::Component::Normal(filename) = components[components.len() - 1] else {
        return Err("Flynn.lua path contains an unsafe component.".into());
    };
    open_at(&parent, filename, false).map_err(|error| {
        format!("Could not safely open Flynn.lua without following links: {error}")
    })
}

#[cfg(windows)]
fn open_relative_without_links(
    root: &Path,
    relative: &Path,
    after_root_open: impl FnOnce(),
) -> Result<fs::File, String> {
    use std::{
        ffi::c_void,
        os::windows::{
            ffi::OsStrExt,
            fs::OpenOptionsExt,
            io::{AsRawHandle, FromRawHandle},
        },
    };

    type Handle = *mut c_void;
    type NtStatus = i32;

    const FILE_ATTRIBUTE_REPARSE_POINT: u32 = 0x0000_0400;
    const FILE_ATTRIBUTE_TAG_INFO_CLASS: i32 = 9;
    const FILE_FLAG_BACKUP_SEMANTICS: u32 = 0x0200_0000;
    const FILE_FLAG_OPEN_REPARSE_POINT: u32 = 0x0020_0000;
    const FILE_OPEN: u32 = 1;
    const FILE_DIRECTORY_FILE: u32 = 0x0000_0001;
    const FILE_NON_DIRECTORY_FILE: u32 = 0x0000_0040;
    const FILE_SYNCHRONOUS_IO_NONALERT: u32 = 0x0000_0020;
    const OBJ_CASE_INSENSITIVE: u32 = 0x0000_0040;
    const FILE_READ_DATA: u32 = 0x0000_0001;
    const FILE_READ_ATTRIBUTES: u32 = 0x0000_0080;
    const SYNCHRONIZE: u32 = 0x0010_0000;
    const FILE_SHARE_ALL: u32 = 0x0000_0007;

    #[repr(C)]
    struct UnicodeString {
        length: u16,
        maximum_length: u16,
        buffer: *mut u16,
    }

    #[repr(C)]
    struct ObjectAttributes {
        length: u32,
        root_directory: Handle,
        object_name: *mut UnicodeString,
        attributes: u32,
        security_descriptor: *mut c_void,
        security_quality_of_service: *mut c_void,
    }

    #[repr(C)]
    struct IoStatusBlock {
        status_or_pointer: usize,
        information: usize,
    }

    #[repr(C)]
    struct FileAttributeTagInfo {
        file_attributes: u32,
        reparse_tag: u32,
    }

    #[link(name = "ntdll")]
    extern "system" {
        fn NtCreateFile(
            file_handle: *mut Handle,
            desired_access: u32,
            object_attributes: *mut ObjectAttributes,
            io_status_block: *mut IoStatusBlock,
            allocation_size: *mut i64,
            file_attributes: u32,
            share_access: u32,
            create_disposition: u32,
            create_options: u32,
            ea_buffer: *mut c_void,
            ea_length: u32,
        ) -> NtStatus;
    }

    #[link(name = "kernel32")]
    extern "system" {
        fn GetFileInformationByHandleEx(
            file: Handle,
            information_class: i32,
            information: *mut c_void,
            buffer_size: u32,
        ) -> i32;
    }

    fn check_not_reparse(file: &fs::File, description: &str) -> Result<(), String> {
        let mut info = FileAttributeTagInfo {
            file_attributes: 0,
            reparse_tag: 0,
        };
        // SAFETY: the handle is live and `info` has the documented layout for
        // FileAttributeTagInfo; Windows writes exactly this structure.
        let ok = unsafe {
            GetFileInformationByHandleEx(
                file.as_raw_handle() as Handle,
                FILE_ATTRIBUTE_TAG_INFO_CLASS,
                (&mut info as *mut FileAttributeTagInfo).cast(),
                std::mem::size_of::<FileAttributeTagInfo>() as u32,
            )
        };
        if ok == 0 {
            return Err(format!(
                "Could not inspect {description}: {}",
                std::io::Error::last_os_error()
            ));
        }
        if info.file_attributes & FILE_ATTRIBUTE_REPARSE_POINT != 0 {
            return Err(format!(
                "Refusing to read Flynn.lua through a Windows reparse point ({description})."
            ));
        }
        Ok(())
    }

    fn open_child(
        parent: &fs::File,
        name: &std::ffi::OsStr,
        directory: bool,
    ) -> Result<fs::File, String> {
        let mut name: Vec<u16> = name.encode_wide().collect();
        if name.is_empty()
            || name
                .iter()
                .any(|unit| *unit == b'/' as u16 || *unit == b'\\' as u16)
        {
            return Err("Flynn.lua path contains an invalid Windows component.".into());
        }
        let byte_length = name
            .len()
            .checked_mul(2)
            .and_then(|length| u16::try_from(length).ok())
            .ok_or_else(|| "Flynn.lua path component is too long.".to_string())?;
        let mut unicode_name = UnicodeString {
            length: byte_length,
            maximum_length: byte_length,
            buffer: name.as_mut_ptr(),
        };
        let mut attributes = ObjectAttributes {
            length: std::mem::size_of::<ObjectAttributes>() as u32,
            root_directory: parent.as_raw_handle() as Handle,
            object_name: &mut unicode_name,
            attributes: OBJ_CASE_INSENSITIVE,
            security_descriptor: std::ptr::null_mut(),
            security_quality_of_service: std::ptr::null_mut(),
        };
        let mut io_status = IoStatusBlock {
            status_or_pointer: 0,
            information: 0,
        };
        let mut handle: Handle = std::ptr::null_mut();
        let options = FILE_OPEN_REPARSE_POINT
            | FILE_SYNCHRONOUS_IO_NONALERT
            | if directory {
                FILE_DIRECTORY_FILE
            } else {
                FILE_NON_DIRECTORY_FILE
            };
        // SAFETY: all pointers reference live stack/local storage for the call;
        // RootDirectory is an open directory handle and ObjectName is one
        // validated component, so resolution cannot escape via an ancestor.
        let status = unsafe {
            NtCreateFile(
                &mut handle,
                FILE_READ_ATTRIBUTES | SYNCHRONIZE | if directory { 0 } else { FILE_READ_DATA },
                &mut attributes,
                &mut io_status,
                std::ptr::null_mut(),
                0,
                FILE_SHARE_ALL,
                FILE_OPEN,
                options,
                std::ptr::null_mut(),
                0,
            )
        };
        if status < 0 {
            return Err(format!("Could not safely traverse Flynn.lua path without following reparse points: NTSTATUS 0x{:08x}.", status as u32));
        }
        // SAFETY: successful NtCreateFile returned a new owned handle.
        let file = unsafe { fs::File::from_raw_handle(handle) };
        check_not_reparse(&file, "path component")?;
        Ok(file)
    }

    let relative_components: Vec<_> = relative.components().collect();
    if relative_components.len() != 6
        || !matches!(relative_components[0], std::path::Component::Normal(flavor) if flavor.to_str().is_some_and(|flavor| FLAVORS.contains(&flavor)))
        || relative_components[1] != std::path::Component::Normal(std::ffi::OsStr::new("WTF"))
        || relative_components[2] != std::path::Component::Normal(std::ffi::OsStr::new("Account"))
        || !matches!(relative_components[3], std::path::Component::Normal(_))
        || relative_components[4]
            != std::path::Component::Normal(std::ffi::OsStr::new("SavedVariables"))
        || relative_components[5] != std::path::Component::Normal(std::ffi::OsStr::new("Flynn.lua"))
    {
        return Err(
            "Flynn.lua path is not a supported source under the selected WoW folder.".into(),
        );
    }

    // Anchor traversal at the selected volume root and open every component
    // relative to the already-open parent handle. UNC roots are intentionally
    // rejected until their server/share root can be anchored with equivalent
    // guarantees.
    let root_components: Vec<_> = root.components().collect();
    let Some(std::path::Component::Prefix(prefix)) = root_components.first() else {
        return Err("Could not safely open WoW folder: expected an absolute drive path.".into());
    };
    let prefix_text = prefix.as_os_str().to_string_lossy();
    let volume_root = match prefix.kind() {
        std::path::Prefix::Disk(_) | std::path::Prefix::VerbatimDisk(_) => {
            format!("{}\\", prefix_text.trim_end_matches(['\\', '/']))
        }
        _ => {
            return Err(
                "Could not safely open WoW folder: UNC and device paths are unsupported.".into(),
            )
        }
    };
    let mut root_handle = fs::OpenOptions::new()
        .read(true)
        .share_mode(FILE_SHARE_ALL)
        .custom_flags(FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT)
        .open(&volume_root)
        .map_err(|error| format!("Could not safely open WoW volume root: {error}"))?;
    check_not_reparse(&root_handle, "volume root")?;
    for component in root_components.iter().skip(1) {
        match component {
            std::path::Component::RootDir => {}
            std::path::Component::Normal(name) => {
                root_handle = open_child(&root_handle, name, true)?
            }
            _ => return Err("WoW folder path contains an unsafe component.".into()),
        }
    }
    after_root_open();
    let mut parent = root_handle;
    for component in relative_components
        .iter()
        .take(relative_components.len() - 1)
    {
        let std::path::Component::Normal(name) = component else {
            return Err("Flynn.lua path contains an unsafe component.".into());
        };
        parent = open_child(&parent, name, true)?;
    }
    let std::path::Component::Normal(filename) = relative_components[relative_components.len() - 1]
    else {
        return Err("Flynn.lua path contains an unsafe component.".into());
    };
    open_child(&parent, filename, false)
}

#[cfg(not(any(target_os = "macos", target_os = "linux", windows)))]
fn open_relative_without_links(
    _root: &Path,
    _relative: &Path,
    _after_root_open: impl FnOnce(),
) -> Result<fs::File, String> {
    Err("This platform cannot safely traverse Flynn.lua paths without following links.".into())
}

fn read_bounded(reader: impl std::io::Read) -> Result<Vec<u8>, String> {
    use std::io::Read;
    let mut content = Vec::with_capacity(MAX_UPLOAD_BYTES as usize);
    reader
        .take(MAX_UPLOAD_BYTES + 1)
        .read_to_end(&mut content)
        .map_err(|error| format!("Could not read Flynn.lua: {error}"))?;
    if content.len() as u64 > MAX_UPLOAD_BYTES {
        return Err("Flynn.lua is larger than the 10 MiB upload limit.".into());
    }
    Ok(content)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{fs, path::Path};
    use tempfile::tempdir;

    fn make_dump(root: &Path, flavor: &str, account: &str) -> PathBuf {
        let file = root
            .join(flavor)
            .join("WTF/Account")
            .join(account)
            .join("SavedVariables/Flynn.lua");
        fs::create_dir_all(file.parent().unwrap()).unwrap();
        fs::write(&file, "FlynnDB = {}\n").unwrap();
        file
    }

    #[test]
    fn discovers_each_flavor_and_keeps_account_scope() {
        let temp = tempdir().unwrap();
        make_dump(temp.path(), "_retail_", "Alpha");
        make_dump(temp.path(), "_classic_era_", "Alpha");
        make_dump(temp.path(), "_forever_", "Alpha");
        make_dump(temp.path(), "_classic_", "Beta");
        let results = discover_files(temp.path(), &BTreeSet::new());
        assert_eq!(results.len(), 4);
        assert!(results
            .iter()
            .any(|file| file.flavor == "retail" && file.account == "Alpha"));
        assert!(results.iter().any(|file| file.flavor == "classic_era"));
        assert!(results
            .iter()
            .any(|file| file.flavor == "forever" && file.account == "Alpha"));
        assert!(results
            .iter()
            .any(|file| file.flavor == "classic" && file.account == "Beta"));
    }

    #[test]
    fn discovers_only_the_immediate_account_saved_variables_path() {
        let temp = tempdir().unwrap();
        let nested = temp
            .path()
            .join("_retail_/WTF/Account/Alpha/archive/SavedVariables/Flynn.lua");
        fs::create_dir_all(nested.parent().unwrap()).unwrap();
        fs::write(&nested, "FlynnDB = {}\n").unwrap();
        let results = discover_files(temp.path(), &BTreeSet::new());
        assert!(results.is_empty());
        let direct = make_dump(temp.path(), "_retail_", "Alpha");
        let results = discover_files(temp.path(), &BTreeSet::new());
        assert_eq!(results.len(), 1);
        assert_eq!(results[0].account, "Alpha");
        assert_eq!(
            Path::new(&results[0].path),
            fs::canonicalize(direct).unwrap()
        );
    }

    #[test]
    fn sync_exclusion_is_flavor_account_specific() {
        let temp = tempdir().unwrap();
        make_dump(temp.path(), "_retail_", "Alpha");
        make_dump(temp.path(), "_classic_", "Alpha");
        let excluded = BTreeSet::from([scope_key("_retail_", "Alpha")]);
        let results = discover_files(temp.path(), &excluded);
        assert!(
            !results
                .iter()
                .find(|file| file.flavor == "retail")
                .unwrap()
                .enabled
        );
        assert!(
            results
                .iter()
                .find(|file| file.flavor == "classic")
                .unwrap()
                .enabled
        );
    }

    #[test]
    fn hashes_contents_and_rejects_oversized_files() {
        let temp = tempdir().unwrap();
        let root = temp.path().join("wow");
        let path = make_dump(&root, "_retail_", "Alpha");
        fs::write(&path, "abc").unwrap();
        let root = fs::canonicalize(root).unwrap();
        let path = fs::canonicalize(path).unwrap();
        let first = read_file_with_hash_under_root(&root, &path).unwrap().1;
        assert_eq!(
            first,
            read_file_with_hash_under_root(&root, &path).unwrap().1
        );
        fs::write(&path, "abd").unwrap();
        assert_ne!(
            first,
            read_file_with_hash_under_root(&root, &path).unwrap().1
        );
        let file = fs::File::create(&path).unwrap();
        file.set_len(MAX_UPLOAD_BYTES + 1).unwrap();
        assert!(read_file_with_hash_under_root(&root, &path)
            .unwrap_err()
            .contains("10 MiB"));
    }

    #[test]
    fn bounded_read_rejects_growth_after_metadata_check() {
        let growing = vec![b'x'; MAX_UPLOAD_BYTES as usize + 1];
        assert!(read_bounded(growing.as_slice())
            .unwrap_err()
            .contains("10 MiB"));
        assert_eq!(read_bounded(&b"small"[..]).unwrap(), b"small");
    }

    #[test]
    fn autodetect_only_accepts_roots_with_supported_flavor_folders() {
        let temp = tempdir().unwrap();
        assert!(!is_wow_root(temp.path()));
        fs::create_dir(temp.path().join("_retail_")).unwrap();
        assert!(is_wow_root(temp.path()));
    }

    #[cfg(unix)]
    #[test]
    fn discovery_rejects_account_and_dump_symlinks_outside_selected_root() {
        use std::os::unix::fs::symlink;

        let temp = tempdir().unwrap();
        let root = temp.path().join("wow");
        let outside = temp.path().join("outside");
        fs::create_dir_all(root.join("_retail_/WTF/Account")).unwrap();
        let outside_dump = make_dump(&outside, "_retail_", "External");

        symlink(&outside, root.join("_retail_/WTF/Account/LinkedAccount")).unwrap();
        let direct_account = root.join("_retail_/WTF/Account/Direct");
        fs::create_dir_all(direct_account.join("SavedVariables")).unwrap();
        symlink(
            &outside_dump,
            direct_account.join("SavedVariables/Flynn.lua"),
        )
        .unwrap();

        assert!(discover_files(&root, &BTreeSet::new()).is_empty());
    }

    #[cfg(unix)]
    #[test]
    fn discovery_allows_symlinks_that_resolve_inside_selected_root() {
        use std::os::unix::fs::symlink;

        let temp = tempdir().unwrap();
        let real = make_dump(temp.path(), "_retail_", "Actual");
        let linked = temp
            .path()
            .join("_retail_/WTF/Account/Alias/SavedVariables/Flynn.lua");
        fs::create_dir_all(linked.parent().unwrap()).unwrap();
        symlink(&real, &linked).unwrap();
        assert_eq!(discover_files(temp.path(), &BTreeSet::new()).len(), 2);
    }

    #[cfg(unix)]
    #[test]
    fn discovery_rejects_in_root_symlinks_that_cross_client_flavors() {
        use std::os::unix::fs::symlink;

        let temp = tempdir().unwrap();
        let retail_dump = make_dump(temp.path(), "_retail_", "Alpha");
        let forever_link = temp
            .path()
            .join("_forever_/WTF/Account/Alpha/SavedVariables/Flynn.lua");
        fs::create_dir_all(forever_link.parent().unwrap()).unwrap();
        symlink(&retail_dump, &forever_link).unwrap();

        let discovered = discover_files(temp.path(), &BTreeSet::new());

        assert_eq!(discovered.len(), 1);
        assert_eq!(discovered[0].flavor, "retail");
        assert_eq!(
            Path::new(&discovered[0].path),
            fs::canonicalize(retail_dump).unwrap()
        );
    }

    #[cfg(unix)]
    #[test]
    fn read_rejects_file_replaced_by_external_symlink_after_discovery() {
        use std::os::unix::fs::symlink;

        let temp = tempdir().unwrap();
        let root = temp.path().join("wow");
        let dump = make_dump(&root, "_retail_", "Alpha");
        let root = fs::canonicalize(root).unwrap();
        let external = temp.path().join("external.lua");
        fs::write(&external, b"EXTERNAL SECRET BYTES").unwrap();
        let discovered = discover_files(&root, &BTreeSet::new()).pop().unwrap();
        let discovered_path = PathBuf::from(discovered.path);

        fs::remove_file(&dump).unwrap();
        symlink(&external, &dump).unwrap();

        let result = read_file_with_hash_under_root(&root, &discovered_path);
        assert!(result.is_err(), "an outward symlink must be rejected");
        assert!(!result
            .map(|(bytes, _)| bytes == b"EXTERNAL SECRET BYTES")
            .unwrap_or(false));
    }

    #[cfg(unix)]
    #[test]
    fn read_rejects_saved_variables_parent_swapped_to_external_symlink_after_root_open() {
        use std::os::unix::fs::symlink;

        let temp = tempdir().unwrap();
        let root = temp.path().join("wow");
        let dump = make_dump(&root, "_retail_", "Alpha");
        let root = fs::canonicalize(root).unwrap();
        let discovered = discover_files(&root, &BTreeSet::new()).pop().unwrap();
        let discovered_path = PathBuf::from(discovered.path);
        let saved_variables = dump.parent().unwrap().to_path_buf();
        let outside = temp.path().join("outside");
        fs::create_dir_all(&outside).unwrap();
        fs::write(outside.join("Flynn.lua"), b"EXTERNAL SECRET BYTES").unwrap();
        let displaced = dump
            .parent()
            .unwrap()
            .with_file_name("SavedVariables.original");

        let result =
            read_file_with_hash_under_root_after_root_open(&root, &discovered_path, || {
                fs::rename(&saved_variables, &displaced).unwrap();
                symlink(&outside, &saved_variables).unwrap();
            });
        assert!(result.is_err(), "a swapped parent symlink must be rejected");
        assert!(!result
            .map(|(bytes, _)| bytes == b"EXTERNAL SECRET BYTES")
            .unwrap_or(false));
    }

    #[cfg(windows)]
    mod windows_path_tests {
        use super::*;
        use std::{
            os::windows::{fs::symlink_file, fs::MetadataExt},
            process::Command,
        };

        const FILE_ATTRIBUTE_REPARSE_POINT: u32 = 0x0000_0400;
        const DUMP_RELATIVE: &str = "_retail_/WTF/Account/Alpha/SavedVariables/Flynn.lua";

        fn assert_is_reparse_point(path: &Path) {
            let metadata = fs::symlink_metadata(path).unwrap_or_else(|error| {
                panic!("reparse fixture {} does not exist: {error}", path.display())
            });
            assert_ne!(
                metadata.file_attributes() & FILE_ATTRIBUTE_REPARSE_POINT,
                0,
                "fixture {} exists but is not a Windows reparse point",
                path.display()
            );
        }

        fn make_junction(link: &Path, target: &Path) {
            let command = format!("mklink /J \"{}\" \"{}\"", link.display(), target.display());
            let output = Command::new("cmd.exe")
                .arg("/C")
                .arg(command)
                .output()
                .expect("cmd.exe must be available to create a junction fixture");
            assert!(
                output.status.success(),
                "could not create junction {} -> {}: {}{}",
                link.display(),
                target.display(),
                String::from_utf8_lossy(&output.stdout),
                String::from_utf8_lossy(&output.stderr)
            );
            assert_is_reparse_point(link);
        }

        fn make_file_symlink(link: &Path, target: &Path) {
            symlink_file(target, link).unwrap_or_else(|error| {
                panic!(
                    "could not create file symlink fixture {} -> {}: {error}",
                    link.display(),
                    target.display()
                )
            });
            assert_is_reparse_point(link);
        }

        #[test]
        fn windows_reads_normal_dump_and_rejects_reparse_points_at_every_path_component() {
            let temp = tempdir().unwrap();
            let root = temp.path().join("wow");
            make_dump(&root, "_retail_", "Alpha");
            let root = fs::canonicalize(root).unwrap();
            let discovered = discover_files(&root, &BTreeSet::new()).pop().unwrap();
            let discovered_path = PathBuf::from(discovered.path);
            assert_eq!(
                read_file_with_hash_under_root(&root, &discovered_path)
                    .unwrap()
                    .0,
                b"FlynnDB = {}\n"
            );

            // Replace each directory component with a real junction in turn.
            // The reparse-point assertion happens before the reader is called,
            // so a denied fixture can never make a rejection assertion pass.
            for component in [
                "_retail_",
                "_retail_/WTF",
                "_retail_/WTF/Account",
                "_retail_/WTF/Account/Alpha",
                "_retail_/WTF/Account/Alpha/SavedVariables",
            ] {
                let temp = tempdir().unwrap();
                let root = temp.path().join("wow");
                make_dump(&root, "_retail_", "Alpha");
                let root = fs::canonicalize(root).unwrap();
                let discovered = discover_files(&root, &BTreeSet::new()).pop().unwrap();
                let discovered_path = PathBuf::from(discovered.path);
                let attacked = root.join(component);
                let displaced = attacked.with_file_name(format!(
                    "{}.original",
                    attacked.file_name().unwrap().to_string_lossy()
                ));
                fs::rename(&attacked, &displaced).unwrap();
                let outside = temp.path().join("outside");
                fs::create_dir_all(&outside).unwrap();
                fs::write(outside.join("Flynn.lua"), b"EXTERNAL SECRET BYTES").unwrap();
                make_junction(&attacked, &outside);

                let result = read_file_with_hash_under_root(&root, &discovered_path);
                assert!(
                    result.is_err(),
                    "reparse point at {component} must be rejected"
                );
                assert!(!result
                    .map(|(bytes, _)| bytes == b"EXTERNAL SECRET BYTES")
                    .unwrap_or(false));
            }

            // The root path itself can have a reparse ancestor. Exercise the
            // handle-relative opener directly so canonicalization cannot erase
            // the junction fixture before it is inspected.
            let temp = tempdir().unwrap();
            let actual_parent = temp.path().join("actual");
            let actual_root = actual_parent.join("wow");
            make_dump(&actual_root, "_retail_", "Alpha");
            let alias = temp.path().join("root-alias");
            make_junction(&alias, &actual_parent);
            let aliased_root = alias.join("wow");
            let result =
                open_relative_without_links(&aliased_root, Path::new(DUMP_RELATIVE), || {});
            assert!(
                result.is_err(),
                "a reparse point in a root ancestor must be rejected"
            );
        }

        #[test]
        fn windows_rejects_file_and_parent_reparse_swaps_after_discovery() {
            // Final-file swap after discovery.
            let temp = tempdir().unwrap();
            let root = temp.path().join("wow");
            let dump = make_dump(&root, "_retail_", "Alpha");
            let root = fs::canonicalize(root).unwrap();
            let discovered = discover_files(&root, &BTreeSet::new()).pop().unwrap();
            let discovered_path = PathBuf::from(discovered.path);
            let outside = temp.path().join("external.lua");
            fs::write(&outside, b"EXTERNAL SECRET BYTES").unwrap();
            fs::remove_file(&dump).unwrap();
            make_file_symlink(&dump, &outside);
            let result = read_file_with_hash_under_root(&root, &discovered_path);
            assert!(
                result.is_err(),
                "a file reparse point swapped after discovery must be rejected"
            );

            // Parent swap after the root handle is opened. Assert the real
            // junction exists inside the callback before traversal resumes.
            let temp = tempdir().unwrap();
            let root = temp.path().join("wow");
            let dump = make_dump(&root, "_retail_", "Alpha");
            let root = fs::canonicalize(root).unwrap();
            let discovered = discover_files(&root, &BTreeSet::new()).pop().unwrap();
            let discovered_path = PathBuf::from(discovered.path);
            let saved_variables = dump.parent().unwrap().to_path_buf();
            let displaced = saved_variables.with_file_name("SavedVariables.original");
            let outside_dir = temp.path().join("outside");
            fs::create_dir_all(&outside_dir).unwrap();
            fs::write(outside_dir.join("Flynn.lua"), b"EXTERNAL SECRET BYTES").unwrap();
            let result =
                read_file_with_hash_under_root_after_root_open(&root, &discovered_path, || {
                    fs::rename(&saved_variables, &displaced).unwrap();
                    make_junction(&saved_variables, &outside_dir);
                    assert_is_reparse_point(&saved_variables);
                });
            assert!(
                result.is_err(),
                "a swapped parent junction must be rejected"
            );
            assert!(!result
                .map(|(bytes, _)| bytes == b"EXTERNAL SECRET BYTES")
                .unwrap_or(false));
        }

        #[test]
        fn windows_unc_path_fails_closed_without_network_access() {
            let result = open_relative_without_links(
                Path::new(r"\\example.invalid\share\wow"),
                Path::new(DUMP_RELATIVE),
                || {},
            );
            let error =
                result.expect_err("UNC paths must be rejected before attempting network access");
            assert!(
                error.contains("UNC and device paths are unsupported"),
                "unexpected error: {error}"
            );
        }
    }
}
