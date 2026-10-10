import { WebSocketServer, type WebSocket, type RawData } from 'ws';
import type { AddressInfo } from 'node:net';

// Test-only protocol fixture, bound to loopback. This is never a product model
// proxy or gateway. A real socket avoids browser-specific interception limits.
const servers = new Set<WebSocketServer>();
export async function socketFixture(handler: (client: WebSocket, message: Buffer, binary: boolean) => void): Promise<string> {
  const server = new WebSocketServer({ host: '127.0.0.1', port: 0 });
  servers.add(server);
  server.on('connection', client => client.on('message', (data: RawData, binary: boolean) => {
    const bytes = Buffer.isBuffer(data) ? data : Array.isArray(data) ? Buffer.concat(data) : Buffer.from(data);
    handler(client, bytes, binary);
  }));
  await new Promise<void>((resolve, reject) => { server.once('listening', resolve); server.once('error', reject); });
  return `ws://127.0.0.1:${(server.address() as AddressInfo).port}/ws`;
}
export async function closeFixtures(): Promise<void> {
  for (const server of servers) {
    for (const client of server.clients) client.terminate();
    await new Promise<void>(resolve => server.close(() => resolve()));
  }
  servers.clear();
}
