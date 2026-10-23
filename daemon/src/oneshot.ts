import type { Options } from '@anthropic-ai/claude-agent-sdk';
import { isolatedOptions, type SessionEnvironment } from './claudeEnv.js';
import { CodedError } from './errors.js';
import { isAuthFailureMessage } from './usageLimit.js';
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

export class OneshotError extends CodedError {
    constructor(message: string) {
        // A refused subscription token gets its own code: the manager marks the agent expired.
        super(502, isAuthFailureMessage(message) ? 'agent_auth_failed' : 'oneshot_failed', message);
    }
}

/**
 * One prompt, no tools, no project settings, on the given agent's
 * subscription. Returns the final text and, with a JSON schema, the
 * structured output.
 */
export async function runOneshot(
    runner: OneshotRunner,
    params: OneshotParams,
    environment: SessionEnvironment,
): Promise<OneshotResult> {
    const options: Options = {
        ...isolatedOptions(environment, params.agentId, params.oauthToken),
        model: params.model,
        systemPrompt: params.systemPrompt,
        // Structured output may take an extra internal turn.
        maxTurns: 3,
    };
    if (params.jsonSchema !== undefined) {
        options.outputFormat = { type: 'json_schema', schema: params.jsonSchema };
    }

    let result: SdkMessageLike | null = null;
    try {
        for await (const message of runner({ prompt: params.userPrompt, options })) {
            if (message.type === 'result') {
                result = message;
            }
        }
    } catch (error) {
        throw new OneshotError(error instanceof Error ? error.message : String(error));
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
