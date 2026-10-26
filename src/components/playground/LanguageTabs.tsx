import type { PlaygroundLanguage } from '../../lib/playground'

export function LanguageTabs({ language, onChange }: { language: PlaygroundLanguage; onChange: (language: PlaygroundLanguage) => void }) {
  return (
    <div className="language-tabs" role="tablist" aria-label="Starter code language">
      {(['php', 'javascript'] as const).map((item) => (
        <button
          key={item}
          type="button"
          role="tab"
          aria-selected={language === item}
          className={`language-tab ${item} ${language === item ? 'active' : ''}`}
          onClick={() => onChange(item)}
        >
          <span aria-hidden="true">{item === 'php' ? 'PHP' : 'JS'}</span>
          {item === 'php' ? 'PHP' : 'JavaScript'}
        </button>
      ))}
    </div>
  )
}
