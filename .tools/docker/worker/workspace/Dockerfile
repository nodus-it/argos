# Workspace layer: an Argos worker image (stack × agent, BYOI included) plus
# the workspace daemon and OpenVSCode Server. Built by WorkspaceImageBuilder
# with the repo root as context; BASE_IMAGE is the resolved worker tag.
ARG BASE_IMAGE
FROM ${BASE_IMAGE}

ARG NODE_VERSION=22.22.1
ARG OPENVSCODE_VERSION=1.109.5

USER root

# Pinned Node runtime for the daemon, independent of the project's own Node
# (BYOI images may ship none or an older one).
RUN set -eux; \
    case "$(uname -m)" in x86_64) arch=x64 ;; aarch64) arch=arm64 ;; *) echo "unsupported arch $(uname -m)" >&2; exit 1 ;; esac; \
    mkdir -p /opt/argos; \
    curl -fsSL "https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-${arch}.tar.xz" | tar -xJ -C /opt/argos; \
    mv "/opt/argos/node-v${NODE_VERSION}-linux-${arch}" /opt/argos/node

RUN set -eux; \
    case "$(uname -m)" in x86_64) arch=x64 ;; aarch64) arch=arm64 ;; *) exit 1 ;; esac; \
    curl -fsSL "https://github.com/gitpod-io/openvscode-server/releases/download/openvscode-server-v${OPENVSCODE_VERSION}/openvscode-server-v${OPENVSCODE_VERSION}-linux-${arch}.tar.gz" | tar -xz -C /opt; \
    mv "/opt/openvscode-server-v${OPENVSCODE_VERSION}-linux-${arch}" /opt/openvscode-server

# Daemon: install with dev deps to compile, then prune. The Claude Agent SDK
# is fetched here, never vendored in the repo.
ENV PATH="/opt/argos/node/bin:${PATH}"
COPY daemon/package.json daemon/package-lock.json /opt/argos/daemon/
RUN cd /opt/argos/daemon && npm ci --no-audit --no-fund
COPY daemon/tsconfig.json daemon/tsconfig.build.json /opt/argos/daemon/
COPY daemon/src /opt/argos/daemon/src
RUN cd /opt/argos/daemon && npm run build && npm prune --omit=dev --no-audit --no-fund

COPY .tools/docker/worker/workspace/workspace-entrypoint.sh /opt/argos/bin/workspace-entrypoint.sh
COPY .tools/docker/worker/workspace/git-credential-argos /opt/argos/bin/git-credential-argos
COPY .tools/docker/worker/workspace/run-gates.sh /opt/argos/bin/run-gates.sh
RUN chmod 0755 /opt/argos/bin/workspace-entrypoint.sh /opt/argos/bin/git-credential-argos /opt/argos/bin/run-gates.sh \
    && mkdir -p /home/agent/.claude-agents /workspace \
    && chown -R agent:agent /home/agent/.claude-agents /workspace

USER agent
WORKDIR /workspace
EXPOSE 7777 3000
ENTRYPOINT ["/opt/argos/bin/workspace-entrypoint.sh"]
CMD []
