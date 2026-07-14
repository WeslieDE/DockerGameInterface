#!/bin/sh
# SGI entrypoint: grant the Apache worker user (www-data) access to the mounted
# Docker socket, then hand off to Apache.
#
# The socket is owned by root:<docker-gid> mode 660 on the host, so www-data
# needs to be a member of the socket's owning group. That GID differs per host,
# so we discover it at runtime and wire up the membership.
set -e

SOCK="${DOCKER_SOCKET:-/var/run/docker.sock}"

if [ -S "$SOCK" ]; then
    SOCK_GID="$(stat -c '%g' "$SOCK" 2>/dev/null || echo '')"

    if [ -n "$SOCK_GID" ] && [ "$SOCK_GID" != "0" ]; then
        # Reuse an existing group with that GID, or create one.
        GNAME="$(getent group "$SOCK_GID" | cut -d: -f1)"
        if [ -z "$GNAME" ]; then
            GNAME="dockerhost"
            groupadd -g "$SOCK_GID" "$GNAME"
        fi
        usermod -aG "$GNAME" www-data
        echo "[sgi] www-data added to group '$GNAME' (gid $SOCK_GID) for $SOCK"
    else
        # Socket owned by the root group — add www-data to root.
        usermod -aG root www-data
        echo "[sgi] socket group is root (gid 0); www-data added to root"
    fi
else
    echo "[sgi] WARNING: docker socket '$SOCK' not found — API calls will fail (502)."
fi

exec apache2-foreground
