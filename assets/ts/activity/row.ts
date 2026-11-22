import { html, nothing, type TemplateResult } from 'lit';
import { icon, reviewTypeSeverity, sourceLabel } from './definitions';
import type { Source } from './types';

/**
 * What a row shows.
 */
export interface RowOptions {
    source: Source;
    /**
     * An icon of the template bundle for what happened, in the color of the channel.
     */
    icon: string | undefined;
    /**
     * What happened, for the tip of the icon, like "Merged".
     */
    iconLabel: string;
    avatar: string | null;
    /**
     * The first line: who did what.
     */
    head: TemplateResult;
    /**
     * The second line: the title of the issue or change, a link to "url".
     */
    title: string;
    url: string;
    /**
     * On the right: the time or the state, and a note below it.
     */
    side: TemplateResult | string;
    note?: string;
    classes?: string[];
}

/**
 * A row of two lines for a narrow list, like the cards of the dashboard: the icon of the channel, the avatar, who
 * did what and below it the title, on the right the time or the state. The stream and the new contributors share
 * it, so their lists read alike. See ".stream-row" in assets/css/module/_stream.scss.
 */
export function row(options: RowOptions): TemplateResult {
    const subject = subjectOf(options.title);
    const classes = ['stream-row', ...options.classes ?? []];

    return html`
        <li class=${classes.join(' ')}>
            <span
                class="stream-event-icon is-${options.source}"
                title="${sourceLabel(options.source)}: ${options.iconLabel}"
            >${options.icon === undefined ? nothing : icon(options.icon)}</span>
            ${options.avatar === null ? html`<span class="stream-row-avatar"></span>` : html`
                <img
                    class="avatar stream-row-avatar"
                    src=${options.avatar}
                    width="32"
                    height="32"
                    alt=""
                    loading="lazy"
                >
            `}
            <span class="stream-row-main">
                <span class="stream-row-head">${options.head}</span>
                <a class="stream-row-title" href=${options.url} title=${options.title}>
                    ${tags(subject.tags)}
                    <span class="stream-event-subject">${subject.text}</span>
                </a>
            </span>
            <span class="stream-row-side">
                ${options.side}
                ${options.note === undefined || options.note === ''
                    ? nothing
                    : html`<span class="stream-row-note">${options.note}</span>`}
            </span>
        </li>
    `;
}

/**
 * The tags of a subject as labels, in the color of their type of change.
 */
export function tags(values: string[]): TemplateResult[] {
    return values.map(tag => html`
        <span class="tag-label tag-label-${reviewTypeSeverity(tag) ?? 'default'}">${tag}</span>
    `);
}

/**
 * The subject of a change apart from the tags it starts with, like "[BUGFIX]" or "[!!!][TASK]": the list shows
 * them as tags, so the eye finds the type of a change.
 */
export function subjectOf(title: string): { tags: string[]; text: string } {
    const lead = /^(?:\[[^\]\s]{1,12}\]\s*)+/.exec(title)?.[0] ?? '';
    const found = Array.from(lead.matchAll(/\[([^\]]+)\]/g), match => match[1] ?? '');

    return { tags: found, text: title.slice(lead.length) };
}
