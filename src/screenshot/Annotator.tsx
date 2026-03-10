import React, { useRef, useEffect, useState, useCallback } from 'react';

type Tool = 'pencil' | 'rectangle' | 'text';

interface AnnotatorProps {
  imageDataUrl: string;
  onSave?: (annotatedDataUrl: string) => void;
}

interface DrawPoint {
  x: number;
  y: number;
}

interface Annotation {
  type: 'pencil' | 'rectangle' | 'text';
  color: string;
  points?: DrawPoint[];
  start?: DrawPoint;
  end?: DrawPoint;
  text?: string;
  position?: DrawPoint;
}

export const Annotator: React.FC<AnnotatorProps> = ({ imageDataUrl, onSave }) => {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const [tool, setTool] = useState<Tool>('pencil');
  const [color, setColor] = useState('#ff0000');
  const [annotations, setAnnotations] = useState<Annotation[]>([]);
  const [isDrawing, setIsDrawing] = useState(false);
  const [currentAnnotation, setCurrentAnnotation] = useState<Annotation | null>(null);
  const imageRef = useRef<HTMLImageElement | null>(null);

  const redraw = useCallback((annots: Annotation[], current: Annotation | null = null) => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const ctx = canvas.getContext('2d')!;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    if (imageRef.current) {
      ctx.drawImage(imageRef.current, 0, 0, canvas.width, canvas.height);
    }
    [...annots, ...(current ? [current] : [])].forEach((ann) => drawAnnotation(ctx, ann));
  }, []);

  useEffect(() => {
    const img = new Image();
    img.src = imageDataUrl;
    img.onload = () => {
      imageRef.current = img;
      const canvas = canvasRef.current;
      if (canvas) {
        canvas.width = img.width || 800;
        canvas.height = img.height || 600;
        redraw(annotations);
      }
    };
  }, [imageDataUrl, annotations, redraw]);

  function drawAnnotation(ctx: CanvasRenderingContext2D, ann: Annotation) {
    ctx.strokeStyle = ann.color;
    ctx.fillStyle = ann.color;
    ctx.lineWidth = 2;
    if (ann.type === 'pencil' && ann.points && ann.points.length > 1) {
      ctx.beginPath();
      ctx.moveTo(ann.points[0].x, ann.points[0].y);
      ann.points.slice(1).forEach((p) => ctx.lineTo(p.x, p.y));
      ctx.stroke();
    } else if (ann.type === 'rectangle' && ann.start && ann.end) {
      ctx.strokeRect(
        ann.start.x,
        ann.start.y,
        ann.end.x - ann.start.x,
        ann.end.y - ann.start.y
      );
    } else if (ann.type === 'text' && ann.text && ann.position) {
      ctx.font = '16px sans-serif';
      ctx.fillText(ann.text, ann.position.x, ann.position.y);
    }
  }

  function getPos(e: React.MouseEvent<HTMLCanvasElement>): DrawPoint {
    const rect = canvasRef.current!.getBoundingClientRect();
    const scaleX = canvasRef.current!.width / rect.width;
    const scaleY = canvasRef.current!.height / rect.height;
    return {
      x: (e.clientX - rect.left) * scaleX,
      y: (e.clientY - rect.top) * scaleY,
    };
  }

  function onMouseDown(e: React.MouseEvent<HTMLCanvasElement>) {
    const pos = getPos(e);
    if (tool === 'text') {
      const text = prompt('Enter annotation text:');
      if (text) {
        const ann: Annotation = { type: 'text', color, text, position: pos };
        const next = [...annotations, ann];
        setAnnotations(next);
        redraw(next);
      }
      return;
    }
    setIsDrawing(true);
    const ann: Annotation =
      tool === 'pencil'
        ? { type: 'pencil', color, points: [pos] }
        : { type: 'rectangle', color, start: pos, end: pos };
    setCurrentAnnotation(ann);
  }

  function onMouseMove(e: React.MouseEvent<HTMLCanvasElement>) {
    if (!isDrawing || !currentAnnotation) return;
    const pos = getPos(e);
    let updated: Annotation;
    if (currentAnnotation.type === 'pencil') {
      updated = { ...currentAnnotation, points: [...(currentAnnotation.points ?? []), pos] };
    } else {
      updated = { ...currentAnnotation, end: pos };
    }
    setCurrentAnnotation(updated);
    redraw(annotations, updated);
  }

  function onMouseUp() {
    if (!isDrawing || !currentAnnotation) return;
    setIsDrawing(false);
    const next = [...annotations, currentAnnotation];
    setAnnotations(next);
    setCurrentAnnotation(null);
    redraw(next);
  }

  function handleClear() {
    setAnnotations([]);
    setCurrentAnnotation(null);
    redraw([]);
  }

  function handleSave() {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const dataUrl = canvas.toDataURL('image/png');
    onSave?.(dataUrl);
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
        <button onClick={() => setTool('pencil')} style={{ fontWeight: tool === 'pencil' ? 'bold' : 'normal' }}>✏️ Pencil</button>
        <button onClick={() => setTool('rectangle')} style={{ fontWeight: tool === 'rectangle' ? 'bold' : 'normal' }}>⬜ Rect</button>
        <button onClick={() => setTool('text')} style={{ fontWeight: tool === 'text' ? 'bold' : 'normal' }}>T Text</button>
        <input type="color" value={color} onChange={(e) => setColor(e.target.value)} title="Color" />
        <button onClick={handleClear}>🗑️ Clear</button>
        <button onClick={handleSave}>💾 Save</button>
      </div>
      <canvas
        ref={canvasRef}
        style={{ border: '1px solid #444', cursor: 'crosshair', maxWidth: '100%' }}
        onMouseDown={onMouseDown}
        onMouseMove={onMouseMove}
        onMouseUp={onMouseUp}
        onMouseLeave={onMouseUp}
      />
    </div>
  );
};
