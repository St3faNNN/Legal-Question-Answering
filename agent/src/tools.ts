import type { Config } from './config.ts'
import type { ToolSchema } from './llm.ts'
import { fetchWithTimeout } from './http.ts'

export const SEARCH_LAW = 'search_law'
export const FOLLOW_REFERENCES = 'follow_references'

/** The search schema handed to the model. Mirrors /api/v1/tools/search-law. */
export const searchLawSchema: ToolSchema = {
    type: 'function',
    function: {
        name: SEARCH_LAW,
        description:
            'Семантичко пребарување во корпус од десет македонски закони (значенско совпаѓање со BGE-M3 dense+sparse, споено со RRF). НЕ е пребарување по клучни зборови и НЕМА оператор за точна фраза — наводниците немаат посебно значење. Во секој резултат, „source“ е членот за цитирање (на пр. „ZAKON-ZA-JAVNITE, чл. 6“), а „text“ е текстот на одредбата. Преформулирањето или ставањето наводници НЕ може да пронајде документ што не постои во корпусот; ако повеќепати добиеш неповрзани резултати, тоа значи дека темата не е опфатена — престани да пребаруваш. Повикувај го повеќепати само за сложени прашања чии делови се во корпусот (на пр. обврската, потоа рокот, потоа казнената одредба).',
        parameters: {
            type: 'object',
            properties: {
                query: {
                    type: 'string',
                    description: 'Краток, конкретен поим на македонски. Пиши природно, без наводници — пребарувањето е семантичко.',
                },
            },
            required: ['query'],
        },
    },
}

/** The traversal schema. Mirrors /api/v1/tools/follow-references. */
export const followReferencesSchema: ToolSchema = {
    type: 'function',
    function: {
        name: FOLLOW_REFERENCES,
        description:
            'Ги враќа членовите што ги цитира ЕДЕН член (појдовните упатувања во графот на цитати). Враќа само идентификатори: „citation“ (за цитирање и за понатамошно пребарување), „heading“ (краток опис на што се однесува упатениот член) и „confidence“ — НЕ враќа текст. Користи го САМО кога пронајден пасус ИЗРЕЧНО зависи од член што го цитира (на пр. „согласно член 28 став 1“, „под условите од членот 55“) и тој член ти треба за да го комплетираш одговорот. НЕ го користи рефлексно за секое упатување што ќе го видиш; ако пасусот е разбирлив сам по себе, не траверзирај. За да го прочиташ текстот на упатениот член, потоа повикај „search_law“ со неговиот наслов (heading).',
        parameters: {
            type: 'object',
            properties: {
                law: {
                    type: 'string',
                    description: 'Кратка ознака на законот — делот пред запирката во полето „source“, на пр. „ZAKON-ZA-JAVNITE“.',
                },
                article: {
                    type: 'string',
                    description: 'Бројот на членот чии упатувања сакаш да ги видиш, на пр. „181“.',
                },
            },
            required: ['law', 'article'],
        },
    },
}

/** Both tools, in the order presented to the model. */
export const allToolSchemas: ToolSchema[] = [searchLawSchema, followReferencesSchema]

/** A tool executor: name + raw JSON arguments string -> a JSON-able result. */
export type ToolExecutor = (name: string, argsJson: string) => Promise<unknown>

/**
 * Execute a tool call against the Laravel tool endpoints. Every failure mode —
 * unknown tool, unparseable arguments, missing fields, non-2xx, network error,
 * timeout — is RETURNED as a structured `{ error }` result rather than thrown,
 * so the loop can feed it back to the model to recover from instead of dying.
 */
export function makeToolExecutor(config: Config): ToolExecutor {
    const searchEndpoint = `${config.appUrl}/api/v1/tools/search-law`
    const referencesEndpoint = `${config.appUrl}/api/v1/tools/follow-references`

    return async (name, argsJson) => {
        const parsed = parseArgs(argsJson)
        if ('error' in parsed) {
            return parsed
        }
        const args = parsed.value

        if (name === SEARCH_LAW) {
            const query = args.query
            if (typeof query !== 'string' || query.trim() === '') {
                return { error: 'the "query" argument is required and must be a non-empty string' }
            }

            return post(config, searchEndpoint, { query })
        }

        if (name === FOLLOW_REFERENCES) {
            const law = args.law
            const article = args.article
            if (typeof law !== 'string' || law.trim() === '' || typeof article !== 'string' || article.trim() === '') {
                return { error: 'both "law" and "article" arguments are required and must be non-empty strings' }
            }

            return post(config, referencesEndpoint, { law, article })
        }

        return { error: `unknown tool: ${name}` }
    }
}

function parseArgs(argsJson: string): { value: Record<string, unknown> } | { error: string } {
    try {
        return { value: JSON.parse(argsJson === '' ? '{}' : argsJson) as Record<string, unknown> }
    } catch {
        return { error: `arguments were not valid JSON: ${argsJson}` }
    }
}

async function post(config: Config, endpoint: string, body: Record<string, unknown>): Promise<unknown> {
    try {
        const response = await fetchWithTimeout(
            endpoint,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    Authorization: `Bearer ${config.apiToken}`,
                },
                body: JSON.stringify(body),
            },
            config.toolTimeoutMs,
        )

        const text = await response.text()
        if (!response.ok) {
            return { error: `tool endpoint returned HTTP ${response.status}`, status: response.status, body: safeJson(text) }
        }

        return safeJson(text)
    } catch (error) {
        return { error: `could not reach the tool endpoint: ${String(error)}` }
    }
}

function safeJson(text: string): unknown {
    try {
        return JSON.parse(text)
    } catch {
        return text
    }
}
