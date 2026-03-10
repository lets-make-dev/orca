export async function captureScreenshot(): Promise<string> {
  const canvas = document.createElement('canvas');
  canvas.width = window.innerWidth;
  canvas.height = window.innerHeight;
  const ctx = canvas.getContext('2d')!;
  ctx.fillStyle = '#f0f0f0';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.fillStyle = '#333';
  ctx.font = '16px monospace';
  ctx.fillText(`Page: ${document.title}`, 20, 40);
  ctx.fillText(`URL: ${window.location.href}`, 20, 70);
  ctx.fillText(`Captured: ${new Date().toISOString()}`, 20, 100);
  return canvas.toDataURL('image/png');
}
