# Vendored addon dependencies

| Library | Pinned upstream | Revision / version | License |
| --- | --- | --- | --- |
| LibStub | https://github.com/lua-wow/LibStub | `af45e4a862a4b0bd025dfac95f4697ecdbc5b015` (upstream mirror; source header identifies revision 103) | Public domain; see `Flynn/Libs/LibStub/LICENSE` |
| LibDeflate | https://github.com/SafeteeWoW/LibDeflate | `afc3b78d12fb3bcfa6b21e5332031ad3d7572e19` (`1.0.2-release`) | zlib; see `Flynn/Libs/LibDeflate/LICENSE` and source header |

Vendored source is unmodified. `LibDeflate:CompressDeflate` emits raw RFC 1951 DEFLATE data; Flynn then encodes those bytes with standard RFC 4648 Base64. `EncodeForPrint` and WoW-specific alphabets are intentionally not used.
