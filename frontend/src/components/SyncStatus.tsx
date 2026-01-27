import './SyncStatus.css'

interface ProgressState {
    status: 'pending' | 'active' | 'complete' | 'error';
    current: number;
    total: number;
    message?: string;
}

interface SyncStatusProps {
    download: ProgressState;
    classify: ProgressState;
    onClose?: () => void;
}

export default function SyncStatus({ download, classify, onClose }: SyncStatusProps) {
    const getPercent = (state: ProgressState) => {
        if (state.total === 0) return 0;
        return Math.min(100, Math.round((state.current / state.total) * 100));
    };

    const getStatusIcon = (status: string) => {
        switch (status) {
            case 'pending': return '⏳';
            case 'active': return '🔄';
            case 'complete': return '✅';
            case 'error': return '❌';
            default: return '•';
        }
    };

    const isComplete = download.status === 'complete' &&
        (classify.status === 'complete' || classify.status === 'pending'); // Pending if no classification needed

    return (
        <div className="sync-status-panel">
            <div className="sync-status-header">
                <h3>Sync Progress</h3>
                {onClose && isComplete && (
                    <button className="close-btn" onClick={onClose}>×</button>
                )}
            </div>

            <div className="status-grid">
                {/* Window 1: Receiving */}
                <div className={`status-window ${download.status}`}>
                    <div className="window-header">
                        <span className="icon">{getStatusIcon(download.status)}</span>
                        <h4>Receiving Emails</h4>
                    </div>
                    <div className="progress-bar-container">
                        <div
                            className="progress-fill"
                            style={{ width: `${getPercent(download)}%` }}
                        />
                    </div>
                    <div className="stats">
                        <span>{download.current} / {download.total}</span>
                        <span className="percent">{getPercent(download)}%</span>
                    </div>
                    {download.message && <p className="status-msg">{download.message}</p>}
                </div>

                {/* Window 2: Classifying */}
                <div className={`status-window ${classify.status}`}>
                    <div className="window-header">
                        <span className="icon">{getStatusIcon(classify.status)}</span>
                        <h4>Classifying</h4>
                    </div>
                    <div className="progress-bar-container">
                        <div
                            className="progress-fill classify"
                            style={{ width: `${getPercent(classify)}%` }}
                        />
                    </div>
                    <div className="stats">
                        <span>{classify.current} / {classify.total}</span>
                        <span className="percent">{getPercent(classify)}%</span>
                    </div>
                    {classify.message && <p className="status-msg">{classify.message}</p>}
                </div>
            </div>
        </div>
    )
}
