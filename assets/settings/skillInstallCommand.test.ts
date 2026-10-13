import { describe, expect, it } from 'vitest';
import meFixture from '../../docs/specs/fixtures/me.agent.json';
import requestsSpecification from '../../docs/specs/rest-api-requests.md?raw';
import { skillInstallCommand } from './skillInstallCommand';

function specifiedCommand(skillUrl: string): string {
    const command = /Install: `(curl [^`]+)`/.exec(requestsSpecification)?.[1];
    if (undefined === command) {
        throw new Error('docs/specs/rest-api-requests.md has no install command.');
    }

    return command.replace('https://<host>/docs/skill/SKILL.md', skillUrl);
}

describe('SkillInstallTest', () => {
    it('Builds the curl install command from skillUrl', () => {
        const skillUrl = meFixture.links.skillUrl;

        const command = skillInstallCommand(skillUrl);

        expect(command).toBe('curl -fsSL https://slopmower.example/docs/skill/SKILL.md --create-dirs -o .claude/skills/slopmower/SKILL.md');
        expect(command).toBe(specifiedCommand(skillUrl));
    });
});
