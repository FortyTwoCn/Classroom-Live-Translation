import { describe, it, expect } from 'vitest';
import { StreamingResampler, pcm16, audioFrame, exceedsBackpressure } from '../../src/audio/dsp';
function tone(rate: number, frequency: number, seconds = 1) { return Float32Array.from({ length: rate * seconds }, (_, index) => Math.sin(index * 2 * Math.PI * frequency / rate)); }
function rms(samples: Float32Array) { const body = samples.subarray(200); return Math.sqrt(body.reduce((sum, value) => sum + value * value, 0) / body.length); }
describe('real audio conversion math (synthetic signals, not hardware verification)', () => {
  it.each([16000, 32000, 44100, 48000, 96000])('converts one second at actual %i Hz into exactly 16000 samples', rate => {
    const resampler = new StreamingResampler(rate);
    expect(resampler.process([tone(rate, 1000)]).length).toBe(16000);
  });
  it('preserves FIR history and fractional resampling positions over arbitrary AudioWorklet blocks', () => {
    const input = tone(44100, 725);
    const complete = new StreamingResampler(44100).process([input]);
    const streaming = new StreamingResampler(44100);
    const output: number[] = [];
    for (let index = 0; index < input.length; index += 128) output.push(...streaming.process([input.subarray(index, index + 128)]));
    expect(Float32Array.from(output)).toEqual(complete);
  });
  it('suppresses 12 kHz alias energy while retaining a 1 kHz speech-band signal', () => {
    const speech = new StreamingResampler(48000).process([tone(48000, 1000)]);
    const alias = new StreamingResampler(48000).process([tone(48000, 12000)]);
    expect(rms(speech)).toBeGreaterThan(0.65);
    expect(rms(alias)).toBeLessThan(0.015);
  });
  it('mixes two channels into mono and sanitizes nonfinite values', () => {
    const output = new StreamingResampler(16000).process([Float32Array.from([1, 0, Number.NaN]), Float32Array.from([-1, 1, 0])]);
    expect(Array.from(output)).toEqual([0, 0.5, 0]);
  });
  it('clips PCM to signed 16-bit little endian without wrapping', () => {
    const buffer = pcm16(Float32Array.from([-2, -1, 0, 1, 2, Number.NaN]));
    const view = new DataView(buffer);
    expect(Array.from({ length: 6 }, (_, i) => view.getInt16(i * 2, true))).toEqual([-32768, -32768, 0, 32767, 32767, 0]);
  });
  it('encodes only the epoch and sequence header as big endian', () => {
    const buffer = audioFrame(0x01020304, 0x05060708, pcm16(Float32Array.from([-1, 1])));
    expect(Array.from(new Uint8Array(buffer))).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 0, 128, 255, 127]);
    expect(() => audioFrame(0, 1, new ArrayBuffer(2))).toThrow();
    expect(() => audioFrame(1, 1, new ArrayBuffer(3))).toThrow();
  });
  it('bounds both browser buffered bytes and unacknowledged packets', () => {
    expect(exceedsBackpressure(0, 9)).toBe(false);
    expect(exceedsBackpressure(64001, 0)).toBe(true);
    expect(exceedsBackpressure(0, 10)).toBe(true);
  });
});
