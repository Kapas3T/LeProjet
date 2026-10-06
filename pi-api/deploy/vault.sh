#!/bin/sh
# Unlock or lock the encrypted vault on the Pi.   Usage: sh deploy/vault.sh unlock | lock
set -e
IMG=/srv/vault.img

case "$1" in
  unlock)
    if mountpoint -q /mnt/vault; then
      echo "Already unlocked."
    else
      sudo cryptsetup open "$IMG" vault        # asks for the vault passphrase
      sudo mount /dev/mapper/vault /mnt/vault
    fi
    sudo systemctl start vault-api
    echo "Vault unlocked, API is starting."
    ;;
  lock)
    sudo systemctl stop vault-api
    sudo umount /mnt/vault
    sudo cryptsetup close vault
    echo "Vault locked."
    ;;
  *)
    echo "Usage: sh $0 unlock|lock"
    exit 1
    ;;
esac
