const sentences = typeof Intl.Segmenter === "function"
  ? new Intl.Segmenter(undefined, { granularity: "sentence" })
  : null;

function splitLongSentence(text: string, limit: number): string[] {
  const characters = Array.from(text);
  const parts: string[] = [];
  for (let start = 0; start < characters.length;) {
    let end = Math.min(start + limit, characters.length);
    if (end < characters.length) {
      for (let i = end - 1; i >= start + Math.floor(limit / 2); i--) {
        if (/[\s，、；：,.!?;:。！？]/u.test(characters[i]!)) {
          end = i + 1;
          break;
        }
      }
    }
    const part = characters.slice(start, end).join("").trim();
    if (part) parts.push(part);
    start = end;
  }
  return parts;
}

/** Wrap provider text without changing its subtitle ID, timing, or saved text. */
export function captionParagraphs(text: string): string[] {
  const limit = /[\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}\p{Script=Hangul}]/u.test(text) ? 64 : 148;
  const output: string[] = [];
  for (const line of text.split(/\r\n|\n|\r/u)) {
    if (!line.trim()) continue;
    if (Array.from(line).length <= limit) {
      output.push(line.trim());
      continue;
    }
    const parts = sentences ? Array.from(sentences.segment(line), item => item.segment) : [line];
    let current = "";
    for (const part of parts) {
      if (current && Array.from(current + part).length > limit) {
        output.push(current.trim());
        current = "";
      }
      if (Array.from(part).length > limit) output.push(...splitLongSentence(part, limit));
      else current += part;
    }
    if (current.trim()) output.push(current.trim());
  }
  return output;
}
