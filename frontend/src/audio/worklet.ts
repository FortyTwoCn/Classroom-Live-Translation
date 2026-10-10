import { StreamingResampler, pcm16 } from './dsp';
declare const sampleRate: number;
declare class AudioWorkletProcessor { port: MessagePort; }
declare function registerProcessor(name: string, processor: typeof AudioWorkletProcessor): void;

class PcmCaptureProcessor extends AudioWorkletProcessor {
  private resampler = new StreamingResampler(sampleRate);
  private packet = new Float32Array(1600);
  private fill = 0;
  private transmitting = false;
  private meterFrames = 0;
  constructor() {
    super();
    this.port.onmessage = ({ data }) => {
      if (data.type === 'start') { this.transmitting = true; this.fill = 0; }
      if (data.type === 'stop') {
        if (this.transmitting && this.fill && data.flush) this.send(this.packet.subarray(0, this.fill));
        this.fill = 0;
        this.transmitting = false;
        this.port.postMessage({ type: 'stopped' });
      }
    };
  }
  private send(samples: Float32Array) {
    const buffer = pcm16(samples);
    this.port.postMessage({ type: 'pcm', buffer }, [buffer]);
  }
  process(inputs: Float32Array[][]): boolean {
    const channels = inputs[0];
    if (!channels?.length) return true;
    const first = channels[0]!;
    this.meterFrames += first.length;
    if (this.meterFrames >= sampleRate / 10) {
      let sum = 0;
      for (const sample of first) sum += sample * sample;
      this.port.postMessage({ type: 'meter', rms: Math.sqrt(sum / first.length) });
      this.meterFrames = 0;
    }
    const output = this.resampler.process(channels);
    if (this.transmitting) for (const sample of output) {
      this.packet[this.fill++] = sample;
      if (this.fill === this.packet.length) { this.send(this.packet); this.fill = 0; }
    }
    return true;
  }
}
registerProcessor('pcm-capture', PcmCaptureProcessor);
