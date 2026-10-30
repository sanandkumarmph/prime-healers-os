#!/usr/bin/env bash
# Generated, pinned PHOS UAT dashboard operator bootstrap. No production target.
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
phos_setup="$(mktemp -d /root/phos-tabbed-dashboard-setup-XXXXXX)"
trap 'phos_code=$?; printf "STOPPED: dashboard setup failed; exit=%s PRIVATE_SETUP=%s\n" "$phos_code" "$phos_setup"; exit "$phos_code"' ERR
phos_release='c0fef0f918ab2ea01be4bc3222f811d9f4a3d664'
phos_base='https://raw.githubusercontent.com/sanandkumarmph/prime-healers-os/c0fef0f918ab2ea01be4bc3222f811d9f4a3d664/'
phos_fetch() {
  curl --fail --silent --show-error --location --max-redirs 3 --connect-timeout 15 --max-time 120 --retry 2 \
    --proto '=https' --proto-redir '=https' "$1" --output "$2"
}
printf 'PHOS_TABBED_DASHBOARD_UAT\nPINNED_RELEASE=%s\n' "$phos_release"
cat > "$phos_setup/manifest.json" <<'PHOS_DASHBOARD_PINNED_MANIFEST'
{
  "format": 1,
  "environment": "PHOS_UAT",
  "baseline": "4b70936f20410fddd3d9707b30907f5000c0b2a9",
  "release": "c0fef0f918ab2ea01be4bc3222f811d9f4a3d664",
  "archive_sha256": "94435c4916fc0fcb39fbfadce9018e479c7d3352431c6228737e565705da04a1",
  "files": [
    {
      "path": "app/Http/Controllers/KnowledgeController.php",
      "sha256": "ce5ca7bd9ab38d5b98011fe3ba716f43d12829b74fe23ba5188d4179634d2225",
      "baseline_sha256": "6de498dd5511a1e2510a4c0109d1a51ac7878d44b01b24549fda47c467c1bb40",
      "size": 27260
    },
    {
      "path": "app/Http/Controllers/RentalController.php",
      "sha256": "e9848e19bcc3c268b191db12d1c4d835b36d684798478e0f7e1777399c0e8788",
      "baseline_sha256": "e9848e19bcc3c268b191db12d1c4d835b36d684798478e0f7e1777399c0e8788",
      "size": 347647
    },
    {
      "path": "public/css/phos-executive-dashboard.css",
      "sha256": "9f19e7fc59140e630985ef6892a6d313697c9e013d06b0da46d33f661ff64658",
      "baseline_sha256": "5cb452a9750f05386135bf8647509725a18a958e86a9a0bc68f31bb1a95ab6b2",
      "size": 33103
    },
    {
      "path": "public/js/phos-executive-dashboard.js",
      "sha256": "3c21ac7e24d8fa77d86bf80d44d1c768440467c8ae0b80eca0f8b6d37775a202",
      "baseline_sha256": "1a90631da4dcd940ce0bbcdb9d1a4959667c53306464b480b4dea31f3550f407",
      "size": 7625
    },
    {
      "path": "resources/views/dashboard.blade.php",
      "sha256": "1e13670a25ea3acc1d9031e5531b01e37478be823108057ff46b89bfbdfeefbe",
      "baseline_sha256": "51e49a2e994e98e8b171d8e71eba4762c5c3ee250925ac0e2356db76f274df4f",
      "size": 579452
    },
    {
      "path": "resources/views/dashboard/executive-navigation.blade.php",
      "sha256": "ebbb5569a125a3a3fe411d168fea5b063ad84da845018ae413f0d86cfdf5c452",
      "baseline_sha256": "afb5d9bfc293f49307abf809c668301667624222bc14e98e79be395ead7d78c2",
      "size": 10197
    },
    {
      "path": "resources/views/dashboard/overview-summary.blade.php",
      "sha256": "4793eccbf9adb8786f3aa027266c34ec7969ee6a264c922c96b60ef3cd9f6b8c",
      "baseline_sha256": "4793eccbf9adb8786f3aa027266c34ec7969ee6a264c922c96b60ef3cd9f6b8c",
      "size": 4622
    },
    {
      "path": "resources/views/knowledge/index.blade.php",
      "sha256": "c34185e3fbfadabd424d84f3a85d6d197e41d62d254cb5b1d2ff70f2931dd022",
      "baseline_sha256": "fa04e2e14440185dea92857a838eb305622bae2243231856505109f0b683d27f",
      "size": 17938
    },
    {
      "path": "resources/views/layouts/app.blade.php",
      "sha256": "950215612c42cc24e4193ff26a8c0b84f9f776782fc808b7fe0b126dfc0c070c",
      "baseline_sha256": "c71f80fad1bc33cd7301156b48fca5ae933f1478e39058f6a936fad0d2ed18b3",
      "size": 192558
    }
  ]
}
PHOS_DASHBOARD_PINNED_MANIFEST
printf '%s  %s\n' '23db211d3ce61fca458144f75ef2fed3ca282cf0622e323216eba214b8758f7a' "$phos_setup/manifest.json" | sha256sum --check --status
phos_fetch "${phos_base}ops/phos-tabbed-dashboard-uat/deploy.py" "$phos_setup/deploy.py"
printf '%s  %s\n' '444d9ffa300758ae8d6019f747836a89e59c9f7d5d50c20e09458ca0f01fd3bc' "$phos_setup/deploy.py" | sha256sum --check --status
while IFS=' ' read -r phos_sha phos_relative; do
  mkdir -p "$phos_setup/source/$(dirname "$phos_relative")"
  phos_fetch "${phos_base}${phos_relative}" "$phos_setup/source/$phos_relative"
  printf '%s  %s\n' "$phos_sha" "$phos_setup/source/$phos_relative" | sha256sum --check --status
done <<'PHOS_DASHBOARD_PINNED_FILES'
ce5ca7bd9ab38d5b98011fe3ba716f43d12829b74fe23ba5188d4179634d2225 app/Http/Controllers/KnowledgeController.php
e9848e19bcc3c268b191db12d1c4d835b36d684798478e0f7e1777399c0e8788 app/Http/Controllers/RentalController.php
9f19e7fc59140e630985ef6892a6d313697c9e013d06b0da46d33f661ff64658 public/css/phos-executive-dashboard.css
3c21ac7e24d8fa77d86bf80d44d1c768440467c8ae0b80eca0f8b6d37775a202 public/js/phos-executive-dashboard.js
1e13670a25ea3acc1d9031e5531b01e37478be823108057ff46b89bfbdfeefbe resources/views/dashboard.blade.php
ebbb5569a125a3a3fe411d168fea5b063ad84da845018ae413f0d86cfdf5c452 resources/views/dashboard/executive-navigation.blade.php
4793eccbf9adb8786f3aa027266c34ec7969ee6a264c922c96b60ef3cd9f6b8c resources/views/dashboard/overview-summary.blade.php
c34185e3fbfadabd424d84f3a85d6d197e41d62d254cb5b1d2ff70f2931dd022 resources/views/knowledge/index.blade.php
950215612c42cc24e4193ff26a8c0b84f9f776782fc808b7fe0b126dfc0c070c resources/views/layouts/app.blade.php
PHOS_DASHBOARD_PINNED_FILES
python3 - "$phos_setup" <<'PHOS_DASHBOARD_BUILD_ZIP'
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
PHOS_DASHBOARD_BUILD_ZIP
printf '%s  %s\n' '94435c4916fc0fcb39fbfadce9018e479c7d3352431c6228737e565705da04a1' "$phos_setup/release.zip" | sha256sum --check --status
python3 - "$phos_setup/deploy.py" <<'PHOS_DASHBOARD_HELPER_SYNTAX'
import pathlib,sys
path=pathlib.Path(sys.argv[1]);compile(path.read_bytes(),str(path),'exec')
print('PHOS_DASHBOARD_HELPER_SYNTAX_OK')
PHOS_DASHBOARD_HELPER_SYNTAX
phos_args=(--manifest "$phos_setup/manifest.json" --archive "$phos_setup/release.zip"
  --manifest-sha256 '23db211d3ce61fca458144f75ef2fed3ca282cf0622e323216eba214b8758f7a' --archive-sha256 '94435c4916fc0fcb39fbfadce9018e479c7d3352431c6228737e565705da04a1' --expected-release "$phos_release")
python3 "$phos_setup/deploy.py" --check-package "${phos_args[@]}"
if [ "${1:-}" = '--check-only' ]; then printf 'PACKAGE_CHECK_COMPLETE NO_APPLICATION_CHANGES=true\n'; exit 0; fi
python3 "$phos_setup/deploy.py" --deploy "${phos_args[@]}"
