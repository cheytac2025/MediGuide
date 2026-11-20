#!/usr/bin/env bash
# Shared helper: rebuild Laravel config cache readable by php-fpm + queue
# (both www-data) without world-readable secrets and without adding mediguide
# to the www-data group.
#
# Preferred one-time root posture (NO usermod):
#   chown mediguide:www-data bootstrap/cache
#   chmod 2750 bootstrap/cache   # setgid: new files inherit group www-data
#
# mediguide does not need supplementary group www-data. setgid inheritance
# assigns the directory group to new files even when the creator is outside
# that group. Directory mode 2750 keeps group write off (www-data cannot
# modify cache files).
#
# Runtime: umask 027 => new files mode 640. Verify group www-data.
# On failure: restore previous config.php if backed up, else config:clear.

mediguide_secure_config_cache() {
  local app_root="${1:-${APP_ROOT:-/var/www/mediguide}}"
  local cache_dir="$app_root/bootstrap/cache"
  local previous_umask backup_dir backup_config=""
  previous_umask=$(umask)

  if [[ ! -d "$cache_dir" ]]; then
    echo "FAIL: missing $cache_dir"
    return 1
  fi

  # Preserve last known-good cache for automatic rollback.
  backup_dir=$(mktemp -d /tmp/mediguide-config-cache.XXXXXX)
  chmod 700 "$backup_dir"
  if [[ -f "$cache_dir/config.php" ]]; then
    cp -a "$cache_dir/config.php" "$backup_dir/config.php"
    backup_config="$backup_dir/config.php"
    echo "PRIOR_CONFIG_BACKUP=$backup_config"
  else
    echo "PRIOR_CONFIG_BACKUP=none"
  fi

  _mediguide_restore_or_clear() {
    local why="${1:-failure}"
    if [[ -n "$backup_config" && -f "$backup_config" ]]; then
      cp -a "$backup_config" "$cache_dir/config.php"
      chmod 640 "$cache_dir/config.php" || true
      # Best-effort group; setgid dir should already be www-data.
      chgrp www-data "$cache_dir/config.php" 2>/dev/null || true
      echo "CONFIG_CACHE=restored_prior ($why)"
      stat -c 'restored owner=%U group=%G mode=%a' "$cache_dir/config.php" || true
    else
      (
        cd "$app_root"
        php artisan config:clear
      ) || true
      echo "CONFIG_CACHE=cleared ($why; no prior cache to restore)"
    fi
    rm -rf "$backup_dir"
  }

  # Restrictive umask for this rebuild only; always restore caller umask.
  umask 027

  (
    cd "$app_root"
    php artisan config:clear
    # Recreate package/service caches so setgid assigns group www-data.
    # In-place overwrites of old mediguide:mediguide files keep the wrong group.
    rm -f "$cache_dir/packages.php" "$cache_dir/services.php"
    php artisan package:discover --ansi >/dev/null
    php artisan config:cache
  )
  local rc=$?
  umask "$previous_umask"

  if [[ $rc -ne 0 ]]; then
    echo "FAIL: config cache rebuild failed"
    _mediguide_restore_or_clear "artisan_failed"
    return "$rc"
  fi

  if [[ ! -f "$cache_dir/config.php" ]]; then
    echo "FAIL: config.php was not created"
    _mediguide_restore_or_clear "missing_config_php"
    return 1
  fi

  local f sudo_cmd="${MEDIGUIDE_SUDO:-}"
  for f in "$cache_dir"/config.php "$cache_dir"/packages.php "$cache_dir"/services.php; do
    [[ -f "$f" ]] || continue
    chmod 640 "$f" || true

    if chgrp www-data "$f" 2>/dev/null; then
      :
    elif [[ -n "$sudo_cmd" ]] && $sudo_cmd chgrp www-data "$f"; then
      $sudo_cmd chmod 640 "$f" || true
    else
      local group
      group=$(stat -c '%G' "$f")
      if [[ "$group" != "www-data" ]]; then
        echo "WARN: $f group=$group (need setgid on cache dir or one-shot sudo chgrp)"
        _mediguide_restore_or_clear "wrong_group"
        return 2
      fi
    fi

    local mode
    mode=$(stat -c '%a' "$f")
    if [[ "$mode" != "640" ]]; then
      chmod 640 "$f" || true
      mode=$(stat -c '%a' "$f")
    fi
    if [[ "$mode" != "640" ]]; then
      echo "WARN: $f mode=$mode (expected 640)"
      _mediguide_restore_or_clear "bad_mode"
      return 2
    fi
    # Final hard stop: never leave world-readable secret cache in place.
    if [[ "${mode: -1}" != "0" ]]; then
      echo "FAIL: $f world-accessible (mode=$mode)"
      _mediguide_restore_or_clear "world_readable"
      return 1
    fi
  done

  rm -rf "$backup_dir"

  echo "CONFIG_CACHE=ok"
  echo "CALLER_UMASK_RESTORED=$previous_umask"
  stat -c 'config.php owner=%U group=%G mode=%a' "$cache_dir/config.php"
  return 0
}

mediguide_verify_cache_readable_by_www_data() {
  local app_root="${1:-${APP_ROOT:-/var/www/mediguide}}"
  local config_php="$app_root/bootstrap/cache/config.php"

  if [[ ! -f "$config_php" ]]; then
    echo "CACHE_PRESENT=no"
    return 1
  fi

  local mode group owner
  mode=$(stat -c '%a' "$config_php")
  group=$(stat -c '%G' "$config_php")
  owner=$(stat -c '%U' "$config_php")
  echo "CACHE_PRESENT=yes owner=$owner group=$group mode=$mode"

  if [[ "$group" != "www-data" || "$mode" != "640" ]]; then
    echo "CACHE_WWW_DATA_READ=no"
    return 1
  fi
  # Owner mediguide + group www-data + 640 => www-data has group-read.
  # Both php-fpm pool and mediguide-queue run as www-data.
  echo "CACHE_WWW_DATA_READ=yes (fpm+queue)"
  return 0
}

# Backward-compatible alias
mediguide_verify_cache_readable_by_fpm() {
  mediguide_verify_cache_readable_by_www_data "$@"
}
