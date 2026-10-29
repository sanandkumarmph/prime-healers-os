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
phos_release='4b70936f20410fddd3d9707b30907f5000c0b2a9'
phos_base='https://raw.githubusercontent.com/sanandkumarmph/prime-healers-os/4b70936f20410fddd3d9707b30907f5000c0b2a9/'
phos_fetch() {
  curl --fail --silent --show-error --location --max-redirs 3 --connect-timeout 15 --max-time 120 --retry 2 \
    --proto '=https' --proto-redir '=https' "$1" --output "$2"
}
printf 'PHOS_TABBED_DASHBOARD_UAT\nPINNED_RELEASE=%s\n' "$phos_release"
cat > "$phos_setup/manifest.json" <<'PHOS_DASHBOARD_PINNED_MANIFEST'
{
  "format": 1,
  "environment": "PHOS_UAT",
  "baseline": "09c6c23112dfb405c10b28cdde9d5717a16872e9",
  "release": "4b70936f20410fddd3d9707b30907f5000c0b2a9",
  "archive_sha256": "c71fed7b02952ab296b4f9e1701134d01fa777ba7cb77ddfc2e8a8a65e761703",
  "files": [
    {
      "path": "app/Http/Controllers/RentalController.php",
      "sha256": "e9848e19bcc3c268b191db12d1c4d835b36d684798478e0f7e1777399c0e8788",
      "baseline_sha256": "13bbc275b6b45826c07950a06026baa6a57ea76758f4b9a7487fcaac08ed0329",
      "size": 347647
    },
    {
      "path": "public/css/phos-executive-dashboard.css",
      "sha256": "5cb452a9750f05386135bf8647509725a18a958e86a9a0bc68f31bb1a95ab6b2",
      "baseline_sha256": null,
      "size": 34211
    },
    {
      "path": "public/js/phos-executive-dashboard.js",
      "sha256": "1a90631da4dcd940ce0bbcdb9d1a4959667c53306464b480b4dea31f3550f407",
      "baseline_sha256": null,
      "size": 5713
    },
    {
      "path": "resources/views/dashboard.blade.php",
      "sha256": "51e49a2e994e98e8b171d8e71eba4762c5c3ee250925ac0e2356db76f274df4f",
      "baseline_sha256": "58c83b6521bf50ca5493e294c61b5c081e922cb37a56f45950625c7d39206b29",
      "size": 583464
    },
    {
      "path": "resources/views/dashboard/executive-navigation.blade.php",
      "sha256": "afb5d9bfc293f49307abf809c668301667624222bc14e98e79be395ead7d78c2",
      "baseline_sha256": null,
      "size": 2196
    },
    {
      "path": "resources/views/dashboard/overview-summary.blade.php",
      "sha256": "4793eccbf9adb8786f3aa027266c34ec7969ee6a264c922c96b60ef3cd9f6b8c",
      "baseline_sha256": null,
      "size": 4622
    },
    {
      "path": "resources/views/layouts/app.blade.php",
      "sha256": "c71f80fad1bc33cd7301156b48fca5ae933f1478e39058f6a936fad0d2ed18b3",
      "baseline_sha256": "0428aa545b0d2781ff2a161f4980f5ff87dd6e73ca599430e8a722fd34898e3c",
      "size": 185915
    }
  ]
}
PHOS_DASHBOARD_PINNED_MANIFEST
printf '%s  %s\n' 'c4fe26ff184a3ddb49f6b96e462b97d3c82cbe13b5321e0b136879c0ab6c6dda' "$phos_setup/manifest.json" | sha256sum --check --status
phos_fetch "${phos_base}ops/phos-tabbed-dashboard-uat/deploy.py" "$phos_setup/deploy.py"
printf '%s  %s\n' '321194defa64027530c3a050165b637058ae0598ba9e698b95288b24f841f0d0' "$phos_setup/deploy.py" | sha256sum --check --status
while IFS=' ' read -r phos_sha phos_relative; do
  mkdir -p "$phos_setup/source/$(dirname "$phos_relative")"
  phos_fetch "${phos_base}${phos_relative}" "$phos_setup/source/$phos_relative"
  printf '%s  %s\n' "$phos_sha" "$phos_setup/source/$phos_relative" | sha256sum --check --status
done <<'PHOS_DASHBOARD_PINNED_FILES'
e9848e19bcc3c268b191db12d1c4d835b36d684798478e0f7e1777399c0e8788 app/Http/Controllers/RentalController.php
5cb452a9750f05386135bf8647509725a18a958e86a9a0bc68f31bb1a95ab6b2 public/css/phos-executive-dashboard.css
1a90631da4dcd940ce0bbcdb9d1a4959667c53306464b480b4dea31f3550f407 public/js/phos-executive-dashboard.js
51e49a2e994e98e8b171d8e71eba4762c5c3ee250925ac0e2356db76f274df4f resources/views/dashboard.blade.php
afb5d9bfc293f49307abf809c668301667624222bc14e98e79be395ead7d78c2 resources/views/dashboard/executive-navigation.blade.php
4793eccbf9adb8786f3aa027266c34ec7969ee6a264c922c96b60ef3cd9f6b8c resources/views/dashboard/overview-summary.blade.php
c71f80fad1bc33cd7301156b48fca5ae933f1478e39058f6a936fad0d2ed18b3 resources/views/layouts/app.blade.php
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
printf '%s  %s\n' 'c71fed7b02952ab296b4f9e1701134d01fa777ba7cb77ddfc2e8a8a65e761703' "$phos_setup/release.zip" | sha256sum --check --status
python3 - "$phos_setup/deploy.py" <<'PHOS_DASHBOARD_HELPER_SYNTAX'
import pathlib,sys
path=pathlib.Path(sys.argv[1]);compile(path.read_bytes(),str(path),'exec')
print('PHOS_DASHBOARD_HELPER_SYNTAX_OK')
PHOS_DASHBOARD_HELPER_SYNTAX
phos_args=(--manifest "$phos_setup/manifest.json" --archive "$phos_setup/release.zip"
  --manifest-sha256 'c4fe26ff184a3ddb49f6b96e462b97d3c82cbe13b5321e0b136879c0ab6c6dda' --archive-sha256 'c71fed7b02952ab296b4f9e1701134d01fa777ba7cb77ddfc2e8a8a65e761703' --expected-release "$phos_release")
python3 "$phos_setup/deploy.py" --check-package "${phos_args[@]}"
if [ "${1:-}" = '--check-only' ]; then printf 'PACKAGE_CHECK_COMPLETE NO_APPLICATION_CHANGES=true\n'; exit 0; fi
python3 "$phos_setup/deploy.py" --deploy "${phos_args[@]}"
