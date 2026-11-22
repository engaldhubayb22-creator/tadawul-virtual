// Forum markup. Posts are plain text with a small set of tags; this turns
// them into elements. Text is never treated as HTML, links accept only
// http(s) addresses, and pictures and attachments can only point at files
// uploaded to this site.
import { h } from 'vue'

export const SIZES = { 1: 11, 2: 13, 3: 15, 4: 18, 5: 22, 6: 26, 7: 32 }
export const FONTS = {
  tahoma: { label: 'تاهوما', css: 'Tahoma, sans-serif' },
  arial: { label: 'أريال', css: 'Arial, sans-serif' },
  segoe: { label: 'سيغو', css: '"Segoe UI", sans-serif' },
  times: { label: 'تايمز', css: '"Times New Roman", serif' },
  courier: { label: 'كورير', css: '"Courier New", monospace' },
}
export const COLORS = ['#ffffff', '#9aa7b7', '#ea3943', '#f5a623', '#f8e71c', '#16c784', '#3b82f6', '#b06cf5']
export const fileUrl = (id) => '/api/v1/forum/files/' + id

const TAG = /\[(\/?)(b|i|u|s|quote|size|color|font|center|right|left|list|url|img|file|code|hr|\*)(?:=([^\]\n]{1,300}))?\]/gi
const HTTP = /^https?:\/\/[^\s"<>]+$/i
const HEX = /^#[0-9a-f]{3}([0-9a-f]{3})?$/i

function parse(text) {
  const re = new RegExp(TAG.source, 'gi')
  const root = { children: [] }
  const stack = [root]
  const top = () => stack.at(-1)
  const push = (node) => { top().children.push(node) }
  // Everything up to the closing tag, taken as it is; null when it is never closed.
  const raw = (name) => {
    const end = text.toLowerCase().indexOf(`[/${name}]`, re.lastIndex)
    if (end < 0) return null
    const inner = text.slice(re.lastIndex, end)
    re.lastIndex = end + name.length + 3
    return inner
  }
  let last = 0, m
  while ((m = re.exec(text))) {
    if (m.index > last) push(text.slice(last, m.index))
    const tag = m[2].toLowerCase(), closing = !!m[1], arg = m[3]?.trim()
    if (tag === 'hr' && !closing) push({ tag: 'hr' })
    else if (tag === '*' && !closing) {
      if (top().tag === 'li') stack.pop()
      if (top().tag === 'list') { const li = { tag: 'li', children: [] }; push(li); stack.push(li) } else push(m[0])
    } else if (['code', 'img', 'file'].includes(tag) && !closing) {
      const inner = raw(tag)
      if (inner === null) push(m[0])
      else if (tag === 'code') push({ tag, text: inner.replace(/^\n/, '') })
      else if (tag === 'img' && /^\d+$/.test(inner.trim())) push({ tag, id: inner.trim() })
      else if (tag === 'file' && /^\d+$/.test(arg ?? '')) push({ tag, id: arg, name: inner.trim() || 'ملف مرفق' })
      else push(text.slice(m.index, re.lastIndex))
    } else if (!closing) {
      const node = { tag, arg, children: [] }
      push(node); stack.push(node)
    } else {
      if (tag === 'list' && top().tag === 'li') stack.pop()
      const at = stack.findLastIndex((n) => n.tag === tag)
      if (at > 0) stack.length = at
      else push(m[0])
    }
    last = re.lastIndex
  }
  if (last < text.length) push(text.slice(last))
  return root.children
}

const plain = (nodes) => nodes.map((n) => (typeof n === 'string' ? n : n.children ? plain(n.children) : '')).join('')

function paint(nodes) {
  return nodes.map((n) => {
    if (typeof n === 'string') return n
    const kids = () => paint(n.children)
    switch (n.tag) {
      case 'b': case 'i': case 'u': case 's': return h(n.tag, kids())
      case 'quote': return h('blockquote', [n.arg ? h('cite', n.arg + ' كتب:') : null, ...kids()])
      case 'size': return SIZES[n.arg] ? h('span', { style: { fontSize: SIZES[n.arg] + 'px', lineHeight: 1.7 } }, kids()) : kids()
      case 'color': return HEX.test(n.arg ?? '') ? h('span', { style: { color: n.arg } }, kids()) : kids()
      case 'font': return FONTS[n.arg?.toLowerCase()] ? h('span', { style: { fontFamily: FONTS[n.arg.toLowerCase()].css } }, kids()) : kids()
      case 'center': case 'right': case 'left': return h('div', { style: { textAlign: n.tag } }, kids())
      case 'list': return h(n.arg === '1' ? 'ol' : 'ul', paint(n.children.filter((c) => c.tag === 'li')))
      case 'li': return h('li', kids())
      case 'url': {
        const href = n.arg ?? plain(n.children).trim()
        return HTTP.test(href) ? h('a', { href, target: '_blank', rel: 'nofollow noopener noreferrer' }, kids()) : kids()
      }
      case 'img': return h('a', { href: fileUrl(n.id), target: '_blank', rel: 'noopener', class: 'fimg' },
        [h('img', { src: fileUrl(n.id), loading: 'lazy', alt: 'صورة مرفقة' })])
      case 'file': return h('a', { href: fileUrl(n.id), class: 'fattach', download: '' }, '📎 ' + n.name)
      case 'code': return h('pre', n.text)
      case 'hr': return h('hr')
      default: return kids()
    }
  })
}

export const renderBBCode = (text) => paint(parse(text ?? ''))
