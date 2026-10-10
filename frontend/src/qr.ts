import QRCode from "qrcode";
export async function renderQr(
  canvas: HTMLCanvasElement,
  url: string,
  width = 320,
): Promise<void> {
  // Includes a four-module quiet zone. The token stays in this browser.
  await QRCode.toCanvas(canvas, url, {
    width,
    margin: 4,
    errorCorrectionLevel: "M",
    color: { dark: "#000000", light: "#ffffff" },
  });
}
export function wrapCanvasText(
  context: CanvasRenderingContext2D,
  value: string,
  maxWidth: number,
): string[] {
  const lines: string[] = [];
  let line = "";
  for (const character of Array.from(value)) {
    if (character === "\n") {
      lines.push(line);
      line = "";
      continue;
    }
    if (line && context.measureText(line + character).width > maxWidth) {
      lines.push(line);
      line = character;
    } else line += character;
  }
  lines.push(line);
  return lines;
}
export async function qrCard(
  url: string,
  courseName: string,
  lessonName: string,
): Promise<HTMLCanvasElement> {
  const qr = document.createElement("canvas");
  await renderQr(qr, url, 640);
  const card = document.createElement("canvas"),
    context = card.getContext("2d");
  if (!context) throw new Error("qrFailed");
  context.font = "28px system-ui, sans-serif";
  const lines = [
    ...wrapCanvasText(context, courseName, 640),
    ...wrapCanvasText(context, lessonName, 640),
  ];
  card.width = 720;
  card.height = 720 + lines.length * 42;
  context.fillStyle = "#ffffff";
  context.fillRect(0, 0, card.width, card.height);
  context.drawImage(qr, 40, 24);
  context.fillStyle = "#152b33";
  context.font = "28px system-ui, sans-serif";
  context.textAlign = "center";
  context.textBaseline = "top";
  lines.forEach((line, index) => context.fillText(line, 360, 696 + index * 42));
  return card;
}
export function saveCanvas(canvas: HTMLCanvasElement, filename: string): void {
  const link = document.createElement("a");
  link.download = filename.replace(/[\\/:*?"<>|]/g, "_").slice(0, 120) + ".png";
  link.href = canvas.toDataURL("image/png");
  link.click();
}
