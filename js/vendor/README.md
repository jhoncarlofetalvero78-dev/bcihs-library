# Local QR generator

`qrcodegen.js` is the compiled JavaScript library from Project Nayuki, distributed under the MIT License retained at the top of the file.

- Project: https://www.nayuki.io/page/qr-code-generator-library
- Download: https://www.nayuki.io/res/qr-code-generator-library/qrcodegen.js
- Retrieved: 2026-10-27
- Upstream SHA-256: `2511bc17f40a3c41d4a0578995db956b38997334d3d20113a5d4dc5c49c69480`

- Local SHA-256: `d234c412720d72a31af7b57a74c40781547f71386e19c4efa13fb5e1f8674a52`
- Local formatting change: trailing whitespace was removed to pass repository whitespace checks. The code and license are otherwise unchanged.

This file generates the request verification QR entirely in the browser. It has no runtime network dependency. Application rendering is in `js/pickup-qr.js`; keep custom changes outside this vendor file. The former Manage Books catalog QR controls were removed separately in Phase 3B; the request-specific QR workflow continues to use this local generator.
