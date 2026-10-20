import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import type { Options } from '@anthropic-ai/claude-agent-sdk';
import type { SdkMessageLike } from './normalize.js';

/** Runs a single, tool-less prompt (e.g. a commit message). */
export type OneshotRunner = (params: { prompt: string; options: Options }) => AsyncIterable<SdkMessageLike>;

export interface OneshotParams {
    agentId: string;
    oauthToken: string;
    model: string;
    systemPrompt: string;
    userPrompt: string;
    jsonSchema?: Record<string, unknown>;
}

export interface OneshotResult {
    text: string;
    structured: unknown;
    cost_usd: number;
}

export class OneshotError extends Error {}

const STRIPPED_ENV = ['ARGOS_DAEMON_TOKEN', 'ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN', 'CLAUDE_CODE_OAUTH_TOKEN', 'CLAUDE_CONFIG_DIR'];

/**
 * One prompt, no tools, no project settings, on the given agent's
 * subscription. Returns the final text and, with a JSON schema, the
 * structured output.
 */
export async function runOneshot(
    runner: OneshotRunner,
    params: OneshotParams,
    environment: { workspaceDir: string; agentsDir: string; claudeBin?: string; baseEnv?: NodeJS.ProcessEnv },
): Promise<OneshotResult> {
    const configDir = join(environment.agentsDir, params.agentId);
    mkdirSync(configDir, { recursive: true });

    const env: Record<string, string | undefined> = { ...(environment.baseEnv ?? process.env) };
    for (const key of STRIPPED_ENV) {
        delete env[key];
    }
    env.CLAUDE_CODE_OAUTH_TOKEN = params.oauthToken;
    env.CLAUDE_CONFIG_DIR = configDir;

    const options: Options = {
        cwd: environment.workspaceDir,
        model: params.model,
        systemPrompt: params.systemPrompt,
        tools: [],
        settingSources: [],
        // Structured output may take an extra internal turn.
        maxTurns: 3,
        persistSession: false,
        env,
    };
    if (params.jsonSchema !== undefined) {
        options.outputFormat = { type: 'json_schema', schema: params.jsonSchema };
    }
    if (environment.claudeBin !== undefined && environment.claudeBin !== '') {
        options.pathToClaudeCodeExecutable = environment.claudeBin;
    }

    let result: SdkMessageLike | null = null;
    for await (const message of runner({ prompt: params.userPrompt, options })) {
        if (message.type === 'result') {
            result = message;
        }
    }

    if (result === null) {
        throw new OneshotError('Claude returned no result');
    }
    if (result.is_error === true) {
        const errors = Array.isArray(result.errors) ? (result.errors as unknown[]).map(String).join('; ') : '';
        throw new OneshotError(`Claude failed (${String(result.subtype)}): ${errors || String(result.result ?? '')}`.trim());
    }

    let structured: unknown = result.structured_output ?? null;
    const text = typeof result.result === 'string' ? result.result : '';
    if (structured === null && params.jsonSchema !== undefined) {
        // Fallback: the model answered with bare JSON in the text.
        try {
            structured = JSON.parse(text.trim().replace(/^```(?:json)?\s*|\s*```$/g, ''));
        } catch {
            structured = null;
        }
    }

    return { text, structured, cost_usd: typeof result.total_cost_usd === 'number' ? result.total_cost_usd : 0 };
}
