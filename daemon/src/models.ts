import type { Options, SDKUserMessage } from '@anthropic-ai/claude-agent-sdk';
import { applyClaudeBin, claudeChildEnv, type SessionEnvironment } from './claudeEnv.js';
import { CodedError } from './errors.js';
import { mapModels } from './normalize.js';
import type { QueryFactory } from './taskSession.js';

/** How long Claude Code may take to start and answer. */
const MODELS_TIMEOUT_MS = 30_000;

export class ModelsError extends CodedError {
    constructor(message: string) {
        super(502, 'models_failed', message);
    }
}

/**
 * The models a subscription may use — what `/model` shows in Claude Code.
 * Starts Claude Code with an input stream that never yields, asks for the
 * models and closes it again: no message is sent, nothing is billed.
 */
export async function listModels(
    factory: QueryFactory,
    params: { agentId: string; oauthToken: string },
    environment: SessionEnvironment,
    timeoutMs: number = MODELS_TIMEOUT_MS,
): Promise<ReturnType<typeof mapModels>> {
    let release: () => void = () => undefined;
    const idle = new Promise<void>((resolve) => {
        release = resolve;
    });
    async function* silence(): AsyncIterable<SDKUserMessage> {
        await idle;
    }

    const options: Options = {
        cwd: environment.workspaceDir,
        tools: [],
        settingSources: [],
        persistSession: false,
        env: claudeChildEnv(environment, params.agentId, params.oauthToken),
    };
    applyClaudeBin(options, environment);

    const query = factory({ prompt: silence(), options });
    let timer: NodeJS.Timeout | undefined;
    try {
        if (query.supportedModels === undefined) {
            throw new ModelsError('This Claude Code version cannot list models');
        }
        const models = await Promise.race([
            query.supportedModels(),
            new Promise<never>((_, reject) => {
                timer = setTimeout(() => reject(new ModelsError('Claude Code did not answer in time')), timeoutMs);
            }),
        ]);

        return mapModels(models);
    } catch (error) {
        throw error instanceof ModelsError ? error : new ModelsError(error instanceof Error ? error.message : String(error));
    } finally {
        clearTimeout(timer);
        release();
        query.close();
    }
}
