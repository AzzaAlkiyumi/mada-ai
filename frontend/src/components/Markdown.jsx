import { memo } from 'react'
import ReactMarkdown from 'react-markdown'
import rehypeHighlight from 'rehype-highlight'
import remarkGfm from 'remark-gfm'
import 'highlight.js/styles/github-dark.min.css'
import { useI18n } from '../i18n.js'
import { useCopy } from '../useCopy.js'
import { CheckIcon, CopyIcon } from './Icons.jsx'

// Plain text of a hast node (code highlighting splits it into spans).
function textOf(node) {
  if (!node) return ''
  if (node.type === 'text') return node.value
  return (node.children ?? []).map(textOf).join('')
}

function CodeBlock({ node, children }) {
  const { t } = useI18n()
  const [copied, copy] = useCopy()
  const code = node?.children?.[0]
  const language = (code?.properties?.className ?? []).find((c) => String(c).startsWith('language-'))?.slice(9)

  return (
    <div className="not-prose my-3 overflow-hidden rounded-lg border border-stone-800 bg-[#0d1117]" dir="ltr">
      <div className="flex items-center justify-between bg-stone-800/80 px-3 py-1.5 text-xs text-stone-300">
        <span className="font-mono">{language || 'code'}</span>
        <button onClick={() => copy(textOf(code).replace(/\n$/, ''))} className="flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-stone-700">
          {copied ? <CheckIcon /> : <CopyIcon />}
          {copied ? t.copied : t.copyCode}
        </button>
      </div>
      <pre className="overflow-x-auto">{children}</pre>
    </div>
  )
}

const components = {
  pre: CodeBlock,
  a: ({ node: _node, ...props }) => <a {...props} target="_blank" rel="noopener noreferrer" />,
  table: ({ node: _node, ...props }) => (
    <div className="overflow-x-auto">
      <table {...props} />
    </div>
  ),
}

// Raw HTML in replies is not rendered (react-markdown escapes it by default).
function Markdown({ text }) {
  return (
    <div className="markdown prose prose-stone max-w-none dark:prose-invert prose-pre:my-0 prose-code:before:content-none prose-code:after:content-none prose-code:rounded prose-code:bg-stone-100 prose-code:px-1 prose-code:py-0.5 dark:prose-code:bg-stone-800">
      <ReactMarkdown remarkPlugins={[remarkGfm]} rehypePlugins={[[rehypeHighlight, { detect: true }]]} components={components}>
        {text}
      </ReactMarkdown>
    </div>
  )
}

export default memo(Markdown)
