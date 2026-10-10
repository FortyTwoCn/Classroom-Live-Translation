import workletUrl from './worklet.ts?worker&url';
export class MicrophoneCapture {
  private stream: MediaStream | null = null;
  private context: AudioContext | null = null;
  private source: MediaStreamAudioSourceNode | null = null;
  private node: AudioWorkletNode | null = null;
  private gain: GainNode | null = null;
  private stopping = false;
  private stopResolver: (() => void) | null = null;
  private generation = 0;
  onPacket: (buffer: ArrayBuffer) => void = () => {};
  onMeter: (rms: number) => void = () => {};
  onFailure: (code: string) => void = () => {};
  get sampleRate(): number | null { return this.context?.sampleRate ?? null; }
  get active(): boolean { return this.stream !== null; }
  async prepare(deviceId: string): Promise<void> {
    if (!window.isSecureContext) throw new Error('secure_context_required');
    if (!navigator.mediaDevices?.getUserMedia) throw new Error('microphone_api_unavailable');
    if (typeof window.AudioContext !== 'function') throw new Error('audio_worklet_unavailable');
    // Reuse an explicit local-test stream; selecting a device is disabled while
    // it is active. For a fresh stream, preserve the click's activation by
    // creating/resuming AudioContext before the first asynchronous boundary.
    if (this.node && this.stream && this.context?.state === 'running') return;
    if (this.context || this.stream || this.node) await this.stop(false);
    this.stopping = false;
    const generation = ++this.generation;
    const context = new AudioContext({ latencyHint: 'interactive' });
    this.context = context;
    // Called from a user click; resume immediately, before permission/network waits.
    const resume = context.resume();
    const stream = await navigator.mediaDevices.getUserMedia({ video: false, audio: { channelCount: { ideal: 1 }, echoCancellation: true, noiseSuppression: true, autoGainControl: false, ...(deviceId ? { deviceId: { exact: deviceId } } : {}) } });
    if (generation !== this.generation) { stream.getTracks().forEach(track => track.stop()); return; }
    this.stream = stream;
    await resume;
    if (!context.audioWorklet) throw new Error('audio_worklet_unavailable');
    await context.audioWorklet.addModule(workletUrl);
    if (generation !== this.generation) return;
    this.node = new AudioWorkletNode(context, 'pcm-capture', { numberOfInputs: 1, numberOfOutputs: 1, outputChannelCount: [1] });
    this.source = context.createMediaStreamSource(stream);
    this.gain = context.createGain();
    this.gain.gain.value = 0;
    this.source.connect(this.node).connect(this.gain).connect(context.destination);
    this.node.port.onmessage = ({ data }) => {
      if (data.type === 'stopped') this.stopResolver?.();
      if (data.type === 'pcm') this.onPacket(data.buffer);
      if (data.type === 'meter') this.onMeter(data.rms);
    };
    this.node.onprocessorerror = () => this.fail('audio_processor_failed');
    for (const track of stream.getAudioTracks()) track.addEventListener('ended', () => this.fail('microphone_ended'));
    context.onstatechange = () => { if (!this.stopping && context.state !== 'running') this.fail('audio_context_suspended'); };
    if (context.state !== 'running') throw new Error('audio_context_suspended');
  }
  start(): void {
    if (!this.node || this.context?.state !== 'running' || !this.stream?.getAudioTracks().some(track => track.readyState === 'live')) throw new Error('microphone_not_ready');
    this.node.port.postMessage({ type: 'start' });
  }
  private fail(code: string): void { if (!this.stopping) { this.stopping = true; this.onFailure(code); } }
  async stop(flush = true): Promise<void> {
    this.generation++;
    this.stopping = true;
    this.stream?.getTracks().forEach(track => track.stop());
    this.stream = null;
    if (this.node) {
      await new Promise<void>(resolve => {
        const timer = window.setTimeout(resolve, 200);
        this.stopResolver = () => { window.clearTimeout(timer); resolve(); };
        this.node!.port.postMessage({ type: 'stop', flush });
      });
      this.node.disconnect();
      this.node.port.close();
    }
    this.stopResolver = null;
    this.node = null;
    this.source?.disconnect();
    this.source = null;
    this.gain?.disconnect();
    this.gain = null;
    const context = this.context;
    this.context = null;
    if (context && context.state !== 'closed') await context.close();
    this.onMeter(0);
  }
}
