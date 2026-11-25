import { type EdgeLabel, Graph, type GraphLabel, layout, type NodeLabel, type Point } from '@dagrejs/dagre';
import { Heading, Link } from '@primer/react';
import { useId, useMemo } from 'react';
import { Link as RouterLink, useLoaderData } from 'react-router';
import type { IssueStatus } from '../../api/types/common';
import type { IssueGraph, IssueSummary } from '../../api/types/issue';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import graphStyles from './IssueGraph.module.css';
import { APPROVAL_MODE_NAMES } from './ApprovalModeField';
import { ISSUE_STATUS_TEXT, IssueStatusLabel, NoApprovalLabel } from './IssueStatusLabel';
import styles from './Issues.module.css';
import type { ProjectGraphData } from './issueData';

/** In rem, so a node holds its text at each font size of the browser. */
const NODE_WIDTH_REM = 16;
const NODE_HEIGHT_REM = 6.75;
const NODE_GAP_REM = 1;
const COLUMN_GAP_REM = 3.5;

const WAITS_FOR_USER: readonly IssueStatus[] = ['awaiting_user_input', 'in_review'];

type GraphNode = { issue: IssueSummary; blockedBy: string[]; left: number; top: number };

/** The key of the project of an issue key. */
const projectKeyOf = (issueKey: string): string => issueKey.slice(0, issueKey.lastIndexOf('-'));

type GraphLayout = { width: number; height: number; nodeWidth: number; nodeHeight: number; nodes: GraphNode[]; edges: Point[][] };

/**
 * Left to right: a blocker stands left of the issue that waits for it. The nodes are in reading order, by column and then
 * from the top, so a blocker comes before the issue that waits for it. A cycle has one arrow against that direction.
 */
function layoutGraph({ issues, links }: IssueGraph, remPixels: number): GraphLayout {
    const nodeWidth = NODE_WIDTH_REM * remPixels;
    const nodeHeight = NODE_HEIGHT_REM * remPixels;
    const graph = new Graph<GraphLabel, NodeLabel, EdgeLabel>();
    graph.setGraph({ rankdir: 'LR', nodesep: NODE_GAP_REM * remPixels, ranksep: COLUMN_GAP_REM * remPixels, marginx: remPixels, marginy: remPixels });
    for (const { key } of issues) {
        graph.setNode(key, { width: nodeWidth, height: nodeHeight });
    }
    for (const { issue, blockedBy } of links) {
        graph.setEdge(blockedBy, issue, {});
    }
    layout(graph);
    const center = (key: string): Point => ({ x: graph.node(key).x ?? 0, y: graph.node(key).y ?? 0 });
    // The middle of the side that faces `toward`, so the first and the last part of an arrow stay between two columns.
    const side = (key: string, toward: Point): Point => ({ x: center(key).x + (Math.sign(toward.x - center(key).x) * nodeWidth) / 2, y: center(key).y });
    const nodes = issues.map((issue) => {
        const { x, y } = center(issue.key);

        return { issue, blockedBy: links.filter((link) => link.issue === issue.key).map((link) => link.blockedBy), left: x - nodeWidth / 2, top: y - nodeHeight / 2 };
    });

    return {
        width: graph.graph().width ?? 0,
        height: graph.graph().height ?? 0,
        nodeWidth,
        nodeHeight,
        nodes: nodes.toSorted((first, second) => first.left - second.left || first.top - second.top),
        edges: graph.edges().map(({ v: blocker, w: waiting }) => {
            // dagre ends an arrow where it meets the border on its way to the center, which can be the top of a node.
            const bends = (graph.edge(blocker, waiting).points ?? []).slice(1, -1);

            return [side(blocker, bends[0] ?? center(waiting)), ...bends, side(waiting, bends.at(-1) ?? center(blocker))];
        }),
    };
}

/**
 * A node is one link with several texts, so its name is one sentence. The name keeps the order of the texts in the DOM,
 * because speech input finds a link by the text that shows.
 */
/**
 * @param projectKey I9 has every blocker only of an issue of this project, so only such a node gets the mark of an issue
 *     that waits for the user.
 */
function NodeLink({ issue, blockedBy, projectKey }: Pick<GraphNode, 'issue' | 'blockedBy'> & { projectKey: string }) {
    const waits = WAITS_FOR_USER.includes(issue.status) && projectKey === projectKeyOf(issue.key);
    // The status label of `awaiting_user_input` says it already.
    const waitsText = waits && 'in_review' === issue.status;
    const blocked = blockedBy.length > 0 ? `Blocked by ${blockedBy.join(', ')}` : null;

    return (
        <RouterLink
            to={`/issues/${issue.key}`}
            className={graphStyles.node}
            aria-label={`${issue.key}: ${issue.title}. ${ISSUE_STATUS_TEXT[issue.status]}.${waitsText ? ' Waits for you.' : ''}${'self' === issue.approval ? ` ${APPROVAL_MODE_NAMES.self}.` : ''}${null === blocked ? '' : ` ${blocked}.`}`}
            data-testid="graph-node"
            data-waits={waits || undefined}
            data-blocked={null !== blocked || undefined}
        >
            {/* A space after each text, so the text of the link has its words apart, as its name has. */}
            <span className={`${styles.key} ${graphStyles.key}`}>{issue.key}</span>{' '}
            {/* Plain text: a link in a link is not valid (D125). */}
            <span className={graphStyles.title}>{issue.title}</span>{' '}
            <span className={graphStyles.status}>
                <IssueStatusLabel status={issue.status} />
            </span>{' '}
            <span className={graphStyles.state}>
                {waitsText && <strong>Waits for you</strong>} <NoApprovalLabel approval={issue.approval} /> {blocked}
            </span>
        </RouterLink>
    );
}

function DependencyGraph({ graph, projectKey }: { graph: IssueGraph; projectKey: string }) {
    const arrowId = useId();
    const { width, height, nodeWidth, nodeHeight, nodes, edges } = useMemo(() => layoutGraph(graph, Number.parseFloat(getComputedStyle(document.documentElement).fontSize)), [graph]);

    return (
        <div className={graphStyles.area} data-testid="issue-graph">
            <div className={graphStyles.canvas} style={{ width, height }}>
                <svg className={graphStyles.edges} width={width} height={height} aria-hidden="true">
                    <defs>
                        <marker id={arrowId} viewBox="0 0 10 10" refX="10" refY="5" markerWidth="7" markerHeight="7" orient="auto">
                            <path className={graphStyles.arrow} d="M0,0 L10,5 L0,10 Z" />
                        </marker>
                    </defs>
                    {edges.map((points) => {
                        const line = points.map(({ x, y }) => `${x},${y}`).join(' ');

                        return <polyline key={line} points={line} markerEnd={`url(#${arrowId})`} />;
                    })}
                </svg>
                {/* Safari drops the list role of a list without markers. */}
                <ul className={graphStyles.nodes} role="list" aria-label="Issues, each blocker before the issue that waits for it">
                    {nodes.map((node) => (
                        <li key={node.issue.key} style={{ left: node.left, top: node.top, width: nodeWidth, height: nodeHeight }}>
                            <NodeLink issue={node.issue} blockedBy={node.blockedBy} projectKey={projectKey} />
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

/** The open issues of one project that have a dependency or wait for the user, from I9, as a graph. */
export function ProjectGraphRoute() {
    const { project, graph } = useLoaderData<ProjectGraphData>();
    const others = graph.otherOpenIssues;
    const empty = 0 === graph.issues.length;
    useDocumentTitle(`Dependency graph of ${project.name}`);

    return (
        <div className={styles.page}>
            <header className={styles.header}>
                <Link as={RouterLink} to={`/projects/${project.key}`} className={styles.back}>
                    Issues of {project.name}
                </Link>
                <Heading as="h1">Dependency graph of {project.name}</Heading>
                <p className={`${styles.state} ${styles.muted}`}>An arrow goes from an issue to the issue that waits for it. Look first at an issue that waits for you and has no open blocker.</p>
            </header>
            {empty ? (
                <p className={graphStyles.empty} data-testid="issue-graph-empty">
                    No open issue has a dependency or waits for you.
                </p>
            ) : (
                <DependencyGraph graph={graph} projectKey={project.key} />
            )}
            {others > 0 && (
                <p className={styles.state}>
                    <Link as={RouterLink} to={`/projects/${project.key}`}>
                        {others} {empty ? '' : 'more '}open {1 === others ? 'issue has' : 'issues have'} no dependency
                    </Link>
                </p>
            )}
        </div>
    );
}
