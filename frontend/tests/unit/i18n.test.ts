import { describe, it, expect } from 'vitest';
import { messages } from '../../src/i18n';
describe('Phase A language resources', () => {
  it('has exactly the same nonempty keys in Chinese, English and Ukrainian', () => {
    const keys = Object.keys(messages['zh-CN']).sort();
    for (const language of ['en', 'uk'] as const) {
      expect(Object.keys(messages[language]).sort()).toEqual(keys);
      for (const text of Object.values(messages[language])) expect(text.trim().length).toBeGreaterThan(0);
    }
  });
  it('uses uk for Ukrainian and retains Cyrillic characters', () => {
    expect(Object.keys(messages)).toEqual(['zh-CN', 'en', 'uk']);
    expect(messages.uk.app).toContain('Синхронний');
    expect(messages.uk.language).toContain('інтерфейсу');
  });
});
