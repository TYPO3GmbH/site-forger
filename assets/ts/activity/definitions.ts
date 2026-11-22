import type { Kind, Source } from './types';

/**
 * The channels and kinds as App\Enum describes them, and the icons of the template bundle: the server writes them
 * into the page once, see templates/stream/_definitions.html.twig.
 */
export interface SourceDefinition {
    value: Source;
    label: string;
    colorKey: string;
}

export interface KindDefinition {
    value: Kind;
    label: string;
    icon: string;
    colorKey: string;
    verbs: Record<Source, string>;
}

/**
 * Where the first change of a new contributor stands, see App\Enum\StreamNewcomerStateEnum.
 */
export interface NewcomerStateDefinition {
    value: string;
    label: string;
    severity: string;
}

interface Definitions {
    sources: SourceDefinition[];
    kinds: KindDefinition[];
    newcomerStates: NewcomerStateDefinition[];
    reviewTypes: { value: string; severity: string }[];
    firstYear: number;
    profileUrl: string;
}

let definitions: Definitions | null = null;
const icons = new Map<string, Element>();

function load(): Definitions {
    if (definitions === null) {
        const script = document.getElementById('forger-activity-definitions');
        definitions = script
            ? (JSON.parse(script.textContent ?? '{}') as Definitions)
            : {
                sources: [],
                kinds: [],
                newcomerStates: [],
                reviewTypes: [],
                firstYear: new Date().getFullYear(),
                profileUrl: '',
            };
    }

    return definitions;
}

/**
 * The first year with activity, see App\Api\ActivityFilters::FIRST_YEAR: no calendar goes back further.
 */
export function firstYear(): number {
    return load().firstYear;
}

/**
 * The key of the style of a tag of a subject, like "bugfix" for "BUGFIX": the type of a change, see
 * App\Enum\ReviewTypeEnum. Null for another tag.
 */
export function reviewTypeSeverity(tag: string): string | null {
    return load().reviewTypes.find(type => type.value === tag)?.severity ?? null;
}

/**
 * The public profile of a TYPO3 account on my.typo3.org, see App\Twig\LinkTwigExtension::typo3ProfileUrl(). Null
 * without the address.
 */
export function profileUrl(username: string): string | null {
    const pattern = load().profileUrl;

    return pattern === '' ? null : pattern.replace('%s', encodeURIComponent(username.toLowerCase()));
}

export function sources(): SourceDefinition[] {
    return load().sources;
}

export function kinds(): KindDefinition[] {
    return load().kinds;
}

export function kind(value: Kind): KindDefinition | undefined {
    return kinds().find(definition => definition.value === value);
}

export function newcomerState(value: string): NewcomerStateDefinition | undefined {
    return load().newcomerStates.find(definition => definition.value === value);
}

export function sourceLabel(value: Source): string {
    return sources().find(definition => definition.value === value)?.label ?? value;
}

/**
 * A copy of an icon of the template bundle, nothing for one the page lacks.
 */
export function icon(name: string): Node | string {
    if (!icons.has(name)) {
        const template = document.getElementById('forger-activity-icons') as HTMLTemplateElement | null;
        const holder = template?.content.querySelector(`[data-icon="${name}"]`);
        const svg = holder?.firstElementChild;
        if (svg) {
            icons.set(name, svg);
        }
    }

    return icons.get(name)?.cloneNode(true) ?? '';
}
