/** Characters allowed in thread, agent and run ids (also used in checkpoint refs). */
export const ID_CHARS = '[A-Za-z0-9_-]{1,64}';

export const ID_PATTERN = new RegExp(`^${ID_CHARS}$`);
