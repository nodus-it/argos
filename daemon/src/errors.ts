/**
 * An error the command API reports as `{error: {code, message}}` with the
 * given HTTP status. Module-specific errors extend it so the HTTP layer maps
 * them in one place.
 */
export class CodedError extends Error {
    constructor(
        readonly status: number,
        readonly code: string,
        message: string,
    ) {
        super(message);
    }
}
