import { describe, expect, it } from 'vitest';
import { captionParagraphs } from '../../src/caption-paragraphs';

describe('long subtitle layout', () => {
  it('breaks a long Chinese translation at readable boundaries without losing words', () => {
    const sentence = '热力学用能量守恒解释发动机内部的工作过程。';
    const text = sentence.repeat(14);
    const parts = captionParagraphs(text);
    expect(parts.length).toBeGreaterThan(1);
    expect(parts.every(part => part.endsWith('。') && part.length <= 64)).toBe(true);
    expect(parts.join('')).toBe(text);
  });

  it('preserves short captions and explicitly separated paragraphs', () => {
    expect(captionParagraphs(' A short subtitle.\n\nAnother line. ')).toEqual(['A short subtitle.', 'Another line.']);
  });

  it('still breaks an uninterrupted provider segment', () => {
    const text = '连续输出'.repeat(90);
    const parts = captionParagraphs(text);
    expect(parts.every(part => part.length <= 64)).toBe(true);
    expect(parts.join('')).toBe(text);
  });
});
