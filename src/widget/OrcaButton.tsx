import React from 'react';
import './widget.css';

interface OrcaButtonProps {
  isOpen: boolean;
  hasActiveSession: boolean;
  onClick: () => void;
}

export const OrcaButton: React.FC<OrcaButtonProps> = ({ isOpen, hasActiveSession, onClick }) => {
  return (
    <button
      className={`orca-button ${isOpen ? 'active' : ''}`}
      onClick={onClick}
      title="Orca AI Widget"
      aria-label="Toggle Orca AI Widget"
    >
      🐋
      {hasActiveSession && <span className="orca-button-pulse" />}
    </button>
  );
};
