#!/usr/bin/env bash
# Generated, pinned PHOS UAT compact operational pages bootstrap. No production target.
set -Eeuo pipefail
umask 077
if [ "$(id -u)" -ne 0 ]; then printf 'STOP: use the Linux server root terminal. Nothing deployed.\n'; exit 1; fi
if [ "$#" -gt 1 ] || { [ "$#" -eq 1 ] && [ "$1" != '--check-only' ]; }; then
  printf 'STOP: only the optional --check-only argument is accepted.\n'; exit 1
fi
for phos_tool in curl python3 sha256sum mktemp mkdir dirname; do
  command -v "$phos_tool" >/dev/null || { printf 'STOP: required tool unavailable.\n'; exit 1; }
done
python3 -c 'import sys; assert sys.version_info >= (3,9), "Python 3.9+ required"'
phos_setup="$(mktemp -d /root/phos-compact-pages-setup-XXXXXX)"
trap 'phos_code=$?; printf "STOPPED: compact pages setup failed; exit=%s PRIVATE_SETUP=%s\n" "$phos_code" "$phos_setup"; exit "$phos_code"' ERR
phos_release='e8e1b5c6260ff9c9ae2b7f6c6f76ac32c3b9050c'
phos_base='https://raw.githubusercontent.com/sanandkumarmph/prime-healers-os/e8e1b5c6260ff9c9ae2b7f6c6f76ac32c3b9050c/'
phos_fetch() {
  curl --fail --silent --show-error --location --max-redirs 3 --connect-timeout 15 --max-time 120 --retry 2 \
    --proto '=https' --proto-redir '=https' "$1" --output "$2"
}
printf 'PHOS_COMPACT_PAGES_UAT\nPINNED_RELEASE=%s\n' "$phos_release"
cat > "$phos_setup/manifest.json" <<'PHOS_COMPACT_PAGES_PINNED_MANIFEST'
{
  "format": 1,
  "environment": "PHOS_UAT",
  "baseline": "c0fef0f918ab2ea01be4bc3222f811d9f4a3d664",
  "release": "e8e1b5c6260ff9c9ae2b7f6c6f76ac32c3b9050c",
  "archive_sha256": "543ae5177431028e0be7cdccdcc88a54ff5746740fcb782fb5bae16e21ab54b1",
  "files": [
    {
      "path": "public/css/phos-operations.css",
      "sha256": "b0db2b8ddf52b3e6192201800aad541190b2007ebd061890a71383957d1f0559",
      "baseline_sha256": null,
      "size": 12481
    },
    {
      "path": "public/js/phos-operations.js",
      "sha256": "1d1ab8fc6191307946c21dddebf2585b5612949fc69711f6ab87cd6adf428227",
      "baseline_sha256": null,
      "size": 1412
    },
    {
      "path": "resources/views/business-partners/index.blade.php",
      "sha256": "95b718a492f59061c40889385c1ba95e6bf9d62706e52e422051b8291835ba8c",
      "baseline_sha256": "835da1c68b9511257a1be39016b46f221c51b9e4162776fbde4911119835350b",
      "size": 34462
    },
    {
      "path": "resources/views/communications/index.blade.php",
      "sha256": "aa011618d9e5a747c7d8a01690da39e2b05b46963bbdd9eb71f1fd502971b223",
      "baseline_sha256": "4a9c2e3f49932f1bb0027a3f1ff16f78398ddb453816c0f98e5fc146f264c98b",
      "size": 45288
    },
    {
      "path": "resources/views/components/phos-action-icon.blade.php",
      "sha256": "d658a7e06908d6a2d060f3e903e4064657ea798e181f889d71cb411e67a3b212",
      "baseline_sha256": null,
      "size": 890
    },
    {
      "path": "resources/views/components/phos-list-filter.blade.php",
      "sha256": "bd7eac9596e7b2fd861c054b0095634c7648855fcde7076bd5bb903a0c368e3e",
      "baseline_sha256": null,
      "size": 1443
    },
    {
      "path": "resources/views/customers/index.blade.php",
      "sha256": "c1086d0250488430989d1f4d0d5cc102ba4a2c2c9007b2a135133199ad362fbc",
      "baseline_sha256": "1543b6e6666f7952decaa36de49579dafda4b7a9fbdefca6b42773d89d3a1f9c",
      "size": 74978
    },
    {
      "path": "resources/views/deliveries/index.blade.php",
      "sha256": "5da8e962a22b581ed76478376d63a58b8f430f88fda3857ea816fc037f82b732",
      "baseline_sha256": "6ec3d3a9f8f6d5eaea4cf291f7db7ffa822fd880fbd7e92ccd3644b0f39aa753",
      "size": 138304
    },
    {
      "path": "resources/views/layouts/app.blade.php",
      "sha256": "e696febb18b904b68a227fcd8908bb9bd2b33b5957e7f9fc411dd3da9c2773f2",
      "baseline_sha256": "950215612c42cc24e4193ff26a8c0b84f9f776782fc808b7fe0b126dfc0c070c",
      "size": 193207
    },
    {
      "path": "resources/views/pickups/index.blade.php",
      "sha256": "50da4a589de8c734c84bf710da683e32862e790e1d040cb59a32008b3f8f5102",
      "baseline_sha256": "caa83c5b9c595978e463e2d4ab22436d71c4b22b38f4098f35e88e649cc134b9",
      "size": 42671
    },
    {
      "path": "resources/views/renewals/index.blade.php",
      "sha256": "6bc20d1974b9bbe2b1fa59658b56ebe866e8c48a4e22123a417e1a5d01074ae2",
      "baseline_sha256": "626f20f5586c55a662ee57c8543459e301eb7ec7bff15cd6868362f3c28ff9e5",
      "size": 86234
    },
    {
      "path": "resources/views/rentals/index.blade.php",
      "sha256": "f30e8ea7454ef068e17004582d00a21188af43755e4bdae79bcab9d8562546bd",
      "baseline_sha256": "5b8d24184e792beeae095132151c5234bd27d897e2a6533cc4fcb04f7a78c3cf",
      "size": 117036
    },
    {
      "path": "resources/views/sales/index.blade.php",
      "sha256": "e45c5e7cbbe171448a802b544a9270307e08abca2ad599400630e44bf3a52eca",
      "baseline_sha256": "7bbc43c4a5c5a8c410e6ca56d6d0028b002892d472c5d9bf2ba0ef265baa695e",
      "size": 93786
    }
  ]
}
PHOS_COMPACT_PAGES_PINNED_MANIFEST
printf '%s  %s\n' '80b3c22e66c3514fda1e16287b7a4f2a8f3ff178caf767f2da66f0dad3256f08' "$phos_setup/manifest.json" | sha256sum --check --status
phos_fetch "${phos_base}ops/phos-compact-pages-uat/deploy.py" "$phos_setup/deploy.py"
printf '%s  %s\n' '4d78763e84816054877ee3315f71e4696653491f911c1f61f05d8a66eaa3cf0c' "$phos_setup/deploy.py" | sha256sum --check --status
while IFS=' ' read -r phos_sha phos_relative; do
  mkdir -p "$phos_setup/source/$(dirname "$phos_relative")"
  phos_fetch "${phos_base}${phos_relative}" "$phos_setup/source/$phos_relative"
  printf '%s  %s\n' "$phos_sha" "$phos_setup/source/$phos_relative" | sha256sum --check --status
done <<'PHOS_COMPACT_PAGES_PINNED_FILES'
b0db2b8ddf52b3e6192201800aad541190b2007ebd061890a71383957d1f0559 public/css/phos-operations.css
1d1ab8fc6191307946c21dddebf2585b5612949fc69711f6ab87cd6adf428227 public/js/phos-operations.js
95b718a492f59061c40889385c1ba95e6bf9d62706e52e422051b8291835ba8c resources/views/business-partners/index.blade.php
aa011618d9e5a747c7d8a01690da39e2b05b46963bbdd9eb71f1fd502971b223 resources/views/communications/index.blade.php
d658a7e06908d6a2d060f3e903e4064657ea798e181f889d71cb411e67a3b212 resources/views/components/phos-action-icon.blade.php
bd7eac9596e7b2fd861c054b0095634c7648855fcde7076bd5bb903a0c368e3e resources/views/components/phos-list-filter.blade.php
c1086d0250488430989d1f4d0d5cc102ba4a2c2c9007b2a135133199ad362fbc resources/views/customers/index.blade.php
5da8e962a22b581ed76478376d63a58b8f430f88fda3857ea816fc037f82b732 resources/views/deliveries/index.blade.php
e696febb18b904b68a227fcd8908bb9bd2b33b5957e7f9fc411dd3da9c2773f2 resources/views/layouts/app.blade.php
50da4a589de8c734c84bf710da683e32862e790e1d040cb59a32008b3f8f5102 resources/views/pickups/index.blade.php
6bc20d1974b9bbe2b1fa59658b56ebe866e8c48a4e22123a417e1a5d01074ae2 resources/views/renewals/index.blade.php
f30e8ea7454ef068e17004582d00a21188af43755e4bdae79bcab9d8562546bd resources/views/rentals/index.blade.php
e45c5e7cbbe171448a802b544a9270307e08abca2ad599400630e44bf3a52eca resources/views/sales/index.blade.php
PHOS_COMPACT_PAGES_PINNED_FILES
python3 - "$phos_setup" <<'PHOS_COMPACT_PAGES_BUILD_ZIP'
import hashlib,json,pathlib,stat,sys,zipfile
root=pathlib.Path(sys.argv[1]);manifest=json.loads((root/'manifest.json').read_bytes())
with zipfile.ZipFile(root/'release.zip','w',compression=zipfile.ZIP_STORED,allowZip64=False) as archive:
    for entry in sorted(manifest['files'],key=lambda row:row['path']):
        data=(root/'source'/entry['path']).read_bytes()
        if len(data)!=entry['size'] or hashlib.sha256(data).hexdigest()!=entry['sha256']:
            raise RuntimeError('Downloaded file differs from pinned manifest')
        info=zipfile.ZipInfo(entry['path'],date_time=(2026,1,1,0,0,0))
        info.create_system=3;info.external_attr=(stat.S_IFREG|0o644)<<16
        info.compress_type=zipfile.ZIP_STORED;info.extra=b'';info.comment=b''
        archive.writestr(info,data)
print('DETERMINISTIC_RELEASE_ARCHIVE_BUILT')
PHOS_COMPACT_PAGES_BUILD_ZIP
printf '%s  %s\n' '543ae5177431028e0be7cdccdcc88a54ff5746740fcb782fb5bae16e21ab54b1' "$phos_setup/release.zip" | sha256sum --check --status
python3 - "$phos_setup/deploy.py" <<'PHOS_COMPACT_PAGES_HELPER_SYNTAX'
import pathlib,sys
path=pathlib.Path(sys.argv[1]);compile(path.read_bytes(),str(path),'exec')
print('PHOS_COMPACT_PAGES_HELPER_SYNTAX_OK')
PHOS_COMPACT_PAGES_HELPER_SYNTAX
phos_args=(--manifest "$phos_setup/manifest.json" --archive "$phos_setup/release.zip"
  --manifest-sha256 '80b3c22e66c3514fda1e16287b7a4f2a8f3ff178caf767f2da66f0dad3256f08' --archive-sha256 '543ae5177431028e0be7cdccdcc88a54ff5746740fcb782fb5bae16e21ab54b1' --expected-release "$phos_release")
python3 "$phos_setup/deploy.py" --check-package "${phos_args[@]}"
if [ "${1:-}" = '--check-only' ]; then printf 'PACKAGE_CHECK_COMPLETE NO_APPLICATION_CHANGES=true\n'; exit 0; fi
python3 "$phos_setup/deploy.py" --deploy "${phos_args[@]}"
