import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import type { Options } from '@anthropic-ai/claude-agent-sdk';

export interface SessionEnvironment {
    workspaceDir: string;
    agentsDir: string;
    claudeBin?: string;
    /** Base environment for the claude child process (defaults to process.env). */
    baseEnv?: NodeJS.ProcessEnv;
}

/** Variables no child process (claude, gate script) may inherit from the daemon. */
export const STRIPPED_ENV = ['ARGOS_DAEMON_TOKEN', 'ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN', 'CLAUDE_CODE_OAUTH_TOKEN', 'CLAUDE_CONFIG_DIR'] as const;

/** A copy of `base` without the daemon's or anyone's tokens. */
export function strippedEnv(base: NodeJS.ProcessEnv = process.env): Record<string, string | undefined> {
    const env: Record<string, string | undefined> = { ...base };
    for (const key of STRIPPED_ENV) {
        delete env[key];
    }

    return env;
}

/**
 * Environment for a claude child running on `agentId`'s subscription: the
 * agent's own config dir (created on demand) and only its OAuth token.
 */
export function claudeChildEnv(environment: SessionEnvironment, agentId: string, oauthToken: string): Record<string, string | undefined> {
    const configDir = join(environment.agentsDir, agentId);
    mkdirSync(configDir, { recursive: true });

    const env = strippedEnv(environment.baseEnv ?? process.env);
    env.CLAUDE_CODE_OAUTH_TOKEN = oauthToken;
    env.CLAUDE_CONFIG_DIR = configDir;

    return env;
}

/** Point the SDK at a configured `claude` binary, if any. */
export function applyClaudeBin(options: Options, environment: SessionEnvironment): void {
    if (environment.claudeBin !== undefined && environment.claudeBin !== '') {
        options.pathToClaudeCodeExecutable = environment.claudeBin;
    }
}
