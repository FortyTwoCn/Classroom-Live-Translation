/** A causal, windowed-sinc low-pass FIR before conversion to 16 kHz.
 * Rational output positions and persistent FIR history make block boundaries
 * transparent. The ~0.7 ms filter delay is not sentence timing precision. */
export class StreamingResampler {
  private taps: Float64Array;
  private history: Float64Array;
  private pointer = 0;
  private inputIndex = -1;
  private nextNumerator = 0;
  private previous = 0;
  constructor(readonly inputRate: number, readonly outputRate = 16000) {
    if (!Number.isFinite(inputRate) || inputRate < outputRate || outputRate <= 0) throw new Error('invalid_sample_rate');
    const count = inputRate === outputRate ? 1 : 63;
    this.taps = new Float64Array(count);
    this.history = new Float64Array(count);
    if (count === 1) { this.taps[0] = 1; return; }
    const cutoff = outputRate * 0.45 / inputRate;
    const midpoint = (count - 1) / 2;
    let total = 0;
    for (let i = 0; i < count; i++) {
      const x = i - midpoint;
      const sinc = x === 0 ? 2 * cutoff : Math.sin(2 * Math.PI * cutoff * x) / (Math.PI * x);
      this.taps[i] = sinc * (0.54 - 0.46 * Math.cos(2 * Math.PI * i / (count - 1)));
      total += this.taps[i]!;
    }
    for (let i = 0; i < count; i++) this.taps[i] = this.taps[i]! / total;
  }
  process(channels: readonly Float32Array[]): Float32Array {
    const count = channels[0]?.length || 0;
    if (channels.some(channel => channel.length !== count)) throw new Error('channel_length_mismatch');
    const output: number[] = [];
    for (let i = 0; i < count; i++) {
      let mono = 0;
      for (const channel of channels) mono += Number.isFinite(channel[i]) ? channel[i]! : 0;
      mono /= channels.length;
      this.history[this.pointer] = mono;
      let filtered = 0;
      for (let tap = 0; tap < this.taps.length; tap++) filtered += this.taps[tap]! * this.history[(this.pointer - tap + this.history.length) % this.history.length]!;
      this.pointer = (this.pointer + 1) % this.history.length;
      this.inputIndex++;
      while (this.nextNumerator <= this.inputIndex * this.outputRate) {
        const position = this.nextNumerator / this.outputRate;
        const fraction = position - (this.inputIndex - 1);
        output.push(this.inputIndex === 0 ? filtered : this.previous + fraction * (filtered - this.previous));
        this.nextNumerator += this.inputRate;
      }
      this.previous = filtered;
    }
    return Float32Array.from(output);
  }
}

export function pcm16(samples: Float32Array): ArrayBuffer {
  const buffer = new ArrayBuffer(samples.length * 2);
  const view = new DataView(buffer);
  for (let i = 0; i < samples.length; i++) {
    const value = Math.max(-1, Math.min(1, Number.isFinite(samples[i]) ? samples[i]! : 0));
    view.setInt16(i * 2, Math.round(value * (value < 0 ? 32768 : 32767)), true);
  }
  return buffer;
}

/** Epoch and sequence are big endian; signed PCM payload is little endian. */
export function audioFrame(epoch: number, sequence: number, pcm: ArrayBuffer): ArrayBuffer {
  if (!Number.isInteger(epoch) || epoch < 1 || epoch > 0xffffffff || !Number.isInteger(sequence) || sequence < 0 || sequence > 0xffffffff || pcm.byteLength === 0 || pcm.byteLength % 2 !== 0) throw new Error('invalid_audio_frame');
  const buffer = new ArrayBuffer(8 + pcm.byteLength);
  const view = new DataView(buffer);
  view.setUint32(0, epoch, false);
  view.setUint32(4, sequence, false);
  new Uint8Array(buffer, 8).set(new Uint8Array(pcm));
  return buffer;
}

export function exceedsBackpressure(bufferedBytes: number, unacknowledgedPackets: number): boolean {
  return bufferedBytes > 64000 || unacknowledgedPackets >= 10;
}
