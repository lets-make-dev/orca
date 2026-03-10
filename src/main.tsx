import React from 'react';
import ReactDOM from 'react-dom/client';
import { OrcaWidget } from './index';

function DemoApp() {
  const [count, setCount] = React.useState(0);

  React.useEffect(() => {
    const widget = new OrcaWidget({ devOnly: false, endpoint: '' });
    widget.mount();
    return () => widget.unmount();
  }, []);

  return (
    <div style={{ fontFamily: 'system-ui', maxWidth: 800, margin: '0 auto', padding: 40 }}>
      <h1>🐋 Orca Demo</h1>
      <p>This is a demo page for the Orca dev widget. Click the whale button in the bottom right.</p>
      <div style={{ display: 'flex', gap: 12, marginTop: 20, flexWrap: 'wrap' }}>
        <button onClick={() => setCount((c) => c + 1)} style={{ padding: '8px 16px', borderRadius: 6 }}>
          Count: {count}
        </button>
        <button onClick={() => console.log('Demo log', Date.now())} style={{ padding: '8px 16px', borderRadius: 6 }}>
          Log to Console
        </button>
        <button onClick={() => { throw new Error('Demo error from Orca test'); }} style={{ padding: '8px 16px', borderRadius: 6 }}>
          Throw Error
        </button>
        <button onClick={() => fetch('https://jsonplaceholder.typicode.com/posts/1').catch(() => {})} style={{ padding: '8px 16px', borderRadius: 6 }}>
          Make Network Request
        </button>
      </div>
      <div style={{ marginTop: 40, padding: 20, background: '#f5f5f5', borderRadius: 8 }}>
        <h2>Features</h2>
        <ul>
          <li>💬 Chat with AI (mock mode when no endpoint)</li>
          <li>📋 Planning &amp; execution workflows</li>
          <li>🔍 Live page context capture (DOM, console, network)</li>
          <li>📸 Screenshot annotation</li>
          <li>🖥️ Terminal (WebSocket)</li>
          <li>📁 Session management with localStorage persistence</li>
        </ul>
      </div>
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <DemoApp />
  </React.StrictMode>
);
