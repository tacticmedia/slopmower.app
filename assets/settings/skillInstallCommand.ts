/**
 * The install command of docs/specs/rest-api-requests.md section 7. The directory name equals the skill name.
 */
export function skillInstallCommand(skillUrl: string): string {
    return `curl -fsSL ${skillUrl} --create-dirs -o .claude/skills/slopmower/SKILL.md`;
}
