/**
 * The four parametrised full-flow runs. Each covers one platform; the two
 * agents and both git-auth methods are spread across the runs so all of
 * {github, gitlab, bitbucket, gitlab-self-hosted} × {claude-code, codex} ×
 * {pat, oauth} are exercised. The mapping is just data — reorder freely.
 */
export type Platform = 'github' | 'gitlab' | 'bitbucket';
export type Agent = 'claude-code' | 'codex';
export type AuthMethod = 'pat' | 'oauth';

export interface RunConfig {
  /** Human label, used as the test.describe title. */
  name: string;
  platform: Platform;
  agent: Agent;
  authMethod: AuthMethod;
  /** Set for the self-hosted GitLab run; drives ConnectedAccount.instance_url. */
  instanceUrl?: string;
}

export const MATRIX: RunConfig[] = [
  { name: 'GitHub · Claude Code · OAuth', platform: 'github', agent: 'claude-code', authMethod: 'oauth' },
  { name: 'GitLab · Codex · PAT', platform: 'gitlab', agent: 'codex', authMethod: 'pat' },
  { name: 'Bitbucket · Claude Code · PAT', platform: 'bitbucket', agent: 'claude-code', authMethod: 'pat' },
  {
    name: 'GitLab self-hosted · Codex · OAuth',
    platform: 'gitlab',
    agent: 'codex',
    authMethod: 'oauth',
    instanceUrl: 'https://gl.example.test',
  },
];
