import React, { useState, useEffect } from 'react';
import { getUsers, createUser, deleteUser, restoreUser, updateUserPassword, getAIConfig, updateAIConfig, getAvailableModels, type AIConfigUpdate } from '../services/api';
import type { User } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { useNavigate } from 'react-router-dom';

const AdminDashboard: React.FC = () => {
    const [users, setUsers] = useState<User[]>([]);
    const [deletedUsers, setDeletedUsers] = useState<User[]>([]);

    // Helper to format bytes
    const formatBytes = (bytes?: number) => {
        if (bytes === undefined || bytes === null) return '0 B';
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    };
    const [view, setView] = useState<'active' | 'deleted'>('active');
    const [tab, setTab] = useState<'users' | 'ai-config'>('users');
    const [newUserValues, setNewUserValues] = useState({ username: '', password: '', is_admin: false });
    const { user, logout } = useAuth();
    const navigate = useNavigate();
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [successMsg, setSuccessMsg] = useState('');

    // AI Config state
    const [aiConfig, setAiConfig] = useState({ api_url: '', api_key: '', primary_model: '', secondary_model: '' });
    const [availableModels, setAvailableModels] = useState<string[]>([]);
    const [loadingModels, setLoadingModels] = useState(false);

    useEffect(() => {
        if (user && !user.is_admin) {
            navigate('/');
        } else if (user?.is_admin) {
            loadUsers();
            if (tab === 'ai-config') {
                loadAIConfig();
            }
        }
    }, [user, navigate, tab]);

    const loadUsers = async () => {
        setLoading(true);
        try {
            const [activeData, deletedData] = await Promise.all([
                getUsers(false),
                getUsers(true)
            ]);
            setUsers(activeData);
            setDeletedUsers(deletedData);
        } catch (e) {
            console.error(e);
            setError("Failed to load users");
        } finally {
            setLoading(false);
        }
    };

    const loadAIConfig = async () => {
        try {
            console.log('[AI Config] Loading configuration...');
            const config = await getAIConfig();
            console.log('[AI Config] Received from API:', config);

            // Set config with empty api_key for security (backend doesn't return it)
            const newConfig = {
                api_url: config.api_url || '',
                api_key: '', // Never loaded from server for security
                primary_model: config.primary_model || '',
                secondary_model: config.secondary_model || ''
            };

            console.log('[AI Config] Setting state to:', newConfig);
            setAiConfig(newConfig);
        } catch (e: any) {
            console.error('[AI Config] Failed to load:', e);
            console.error('[AI Config] Error details:', e.response?.data || e.message);
            setError('No se pudo cargar la configuración de IA');
        }
    };

    const handleTestConnection = async () => {
        if (!aiConfig.api_url || !aiConfig.api_key) {
            setError('Please enter both API URL and API Key');
            return;
        }

        setLoadingModels(true);
        setError('');
        try {
            const models = await getAvailableModels();
            setAvailableModels(models);
            setSuccessMsg(`Connected! Found ${models.length} models`);
        } catch (e: any) {
            setError('Failed to connect to AI API. Check URL and API Key.');
            setAvailableModels([]);
        } finally {
            setLoadingModels(false);
        }
    };

    const handleSaveAIConfig = async () => {
        // Validate required fields (api_key is optional - if empty, backend keeps existing)
        if (!aiConfig.api_url || !aiConfig.primary_model || !aiConfig.secondary_model) {
            setError('URL de API, Modelo Primario y Modelo Secundario son requeridos');
            return;
        }

        setError('');
        try {
            await updateAIConfig(aiConfig as AIConfigUpdate);

            // Show appropriate message
            if (aiConfig.api_key && aiConfig.api_key.trim()) {
                setSuccessMsg('Configuración guardada exitosamente (API Key actualizada)');
            } else {
                setSuccessMsg('Configuración guardada exitosamente (API Key sin cambios)');
            }

            // Clear the api_key field after save for security
            setAiConfig({ ...aiConfig, api_key: '' });
        } catch (e: any) {
            console.error('[AI Config] Save failed:', e);
            setError(e.response?.data?.detail || 'Error al guardar la configuración');
        }
    };

    const handleCreate = async (e: React.FormEvent) => {
        e.preventDefault();
        setError('');
        setSuccessMsg('');
        try {
            await createUser(newUserValues);
            setNewUserValues({ username: '', password: '', is_admin: false });
            setSuccessMsg("User created successfully");
            loadUsers();
        } catch (e) {
            console.error(e);
            setError("Failed to create user. Username might exist.");
        }
    };

    const handleDelete = async (id: number) => {
        if (!confirm('Are you sure you want to delete this user? They will be moved to deleted items.')) return;
        try {
            await deleteUser(id, false); // Soft delete
            setSuccessMsg('User moved to trash');
            loadUsers();
        } catch (e) {
            setError('Failed to delete user');
        }
    };

    const handleRestore = async (id: number) => {
        try {
            await restoreUser(id);
            setSuccessMsg('User restored');
            loadUsers();
        } catch (e) {
            setError('Failed to restore user');
        }
    };

    const handlePermanentDelete = async (id: number) => {
        if (!confirm('Are you sure? This cannot be undone.')) return;
        try {
            await deleteUser(id, true); // Hard delete
            setSuccessMsg('User permanently deleted');
            loadUsers();
        } catch (e: any) {
            setError(e.response?.data?.detail || 'Failed to permanently delete user');
        }
    }


    const handlePasswordChange = async (id: number, username: string) => {
        const newPassword = prompt(`Enter new password for ${username}:`);
        if (!newPassword) return; // User cancelled

        try {
            await updateUserPassword(id, newPassword);
            setSuccessMsg(`Password updated for ${username}`);
        } catch (e) {
            console.error(e);
            setError("Failed to update password");
        }
    };

    if (loading && users.length === 0 && deletedUsers.length === 0) return <div style={{ padding: '2rem' }}>Loading admin dashboard...</div>;

    return (
        <div style={{ padding: '2rem', maxWidth: '1000px', margin: '0 auto' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '2rem', alignItems: 'center' }}>
                <h1>Admin Dashboard</h1>
                <div style={{ display: 'flex', gap: '1rem' }}>
                    <button onClick={() => navigate('/')} style={{ padding: '0.5rem 1rem', cursor: 'pointer' }}>Go to Mail</button>
                    <button onClick={logout} style={{ padding: '0.5rem 1rem', backgroundColor: '#dc3545', color: 'white', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>Logout</button>
                </div>
            </div>

            {error && <div style={{ backgroundColor: '#f8d7da', color: '#721c24', padding: '1rem', marginBottom: '1rem', borderRadius: '4px' }}>{error}</div>}
            {successMsg && <div style={{ backgroundColor: '#d4edda', color: '#155724', padding: '1rem', marginBottom: '1rem', borderRadius: '4px' }}>{successMsg}</div>}

            {/* Tab Navigation */}
            <div style={{ marginBottom: '2rem', borderBottom: '2px solid #dee2e6' }}>
                <div style={{ display: 'flex', gap: '1rem' }}>
                    <button
                        onClick={() => setTab('users')}
                        style={{
                            padding: '1rem 2rem',
                            border: 'none',
                            background: tab === 'users' ? '#007bff' : 'transparent',
                            color: tab === 'users' ? 'white' : '#495057',
                            fontWeight: tab === 'users' ? 'bold' : 'normal',
                            borderBottom: tab === 'users' ? '3px solid #007bff' : 'none',
                            cursor: 'pointer'
                        }}
                    >
                        Gestión de Usuarios
                    </button>
                    <button
                        onClick={() => setTab('ai-config')}
                        style={{
                            padding: '1rem 2rem',
                            border: 'none',
                            background: tab === 'ai-config' ? '#007bff' : 'transparent',
                            color: tab === 'ai-config' ? 'white' : '#495057',
                            fontWeight: tab === 'ai-config' ? 'bold' : 'normal',
                            borderBottom: tab === 'ai-config' ? '3px solid #007bff' : 'none',
                            cursor: 'pointer'
                        }}
                    >
                        Configuración IA
                    </button>
                </div>
            </div>

            {/* Users Tab */}
            {tab === 'users' && (
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 2fr', gap: '3rem' }}>
                    <div style={{ backgroundColor: '#f8f9fa', padding: '1.5rem', borderRadius: '8px', height: 'fit-content' }}>
                        <h3>Create New User</h3>
                        <form onSubmit={handleCreate} style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
                            <div>
                                <label style={{ display: 'block', marginBottom: '0.5rem' }}>Username</label>
                                <input
                                    style={{ width: '100%', padding: '0.5rem', borderRadius: '4px', border: '1px solid #ced4da' }}
                                    placeholder="Username"
                                    value={newUserValues.username}
                                    onChange={e => setNewUserValues({ ...newUserValues, username: e.target.value })}
                                    required
                                />
                            </div>
                            <div>
                                <label style={{ display: 'block', marginBottom: '0.5rem' }}>Password</label>
                                <input
                                    style={{ width: '100%', padding: '0.5rem', borderRadius: '4px', border: '1px solid #ced4da' }}
                                    placeholder="Password"
                                    type="password"
                                    value={newUserValues.password}
                                    onChange={e => setNewUserValues({ ...newUserValues, password: e.target.value })}
                                    required
                                />
                            </div>
                            <div>
                                <label style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', cursor: 'pointer' }}>
                                    <input
                                        type="checkbox"
                                        checked={newUserValues.is_admin}
                                        onChange={e => setNewUserValues({ ...newUserValues, is_admin: e.target.checked })}
                                    />
                                    Is Admin User
                                </label>
                            </div>
                            <button type="submit" style={{ padding: '0.75rem', backgroundColor: '#28a745', color: 'white', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
                                Create User
                            </button>
                        </form>
                    </div>

                    <div>
                        <div style={{ display: 'flex', gap: '10px', marginBottom: '1rem' }}>
                            <button
                                onClick={() => setView('active')}
                                style={{
                                    padding: '0.5rem 1rem',
                                    border: 'none',
                                    borderBottom: view === 'active' ? '2px solid #007bff' : 'none',
                                    background: 'transparent',
                                    fontWeight: view === 'active' ? 'bold' : 'normal',
                                    cursor: 'pointer'
                                }}
                            >
                                Active Users ({users.length})
                            </button>
                            <button
                                onClick={() => setView('deleted')}
                                style={{
                                    padding: '0.5rem 1rem',
                                    border: 'none',
                                    borderBottom: view === 'deleted' ? '2px solid #007bff' : 'none',
                                    background: 'transparent',
                                    fontWeight: view === 'deleted' ? 'bold' : 'normal',
                                    cursor: 'pointer',
                                    color: '#dc3545'
                                }}
                            >
                                Deleted Users ({deletedUsers.length})
                            </button>
                        </div>

                        <div style={{ border: '1px solid #dee2e6', borderRadius: '4px' }}>
                            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                                <thead>
                                    <tr style={{ backgroundColor: '#e9ecef', textAlign: 'left' }}>
                                        <th style={{ padding: '0.75rem', borderBottom: '1px solid #dee2e6' }}>ID</th>
                                        <th style={{ padding: '0.75rem', borderBottom: '1px solid #dee2e6' }}>Username</th>
                                        <th style={{ padding: '0.75rem', borderBottom: '1px solid #dee2e6' }}>Role</th>
                                        <th style={{ padding: '0.75rem', borderBottom: '1px solid #dee2e6' }}>Storage</th>
                                        <th style={{ padding: '0.75rem', borderBottom: '1px solid #dee2e6' }}>Status</th>
                                        <th style={{ padding: '0.75rem', borderBottom: '1px solid #dee2e6' }}>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {view === 'active' ? (
                                        users.map(u => (
                                            <tr key={u.id} style={{ borderBottom: '1px solid #dee2e6' }}>
                                                <td style={{ padding: '0.75rem' }}>{u.id}</td>
                                                <td style={{ padding: '0.75rem' }}>{u.username}</td>
                                                <td style={{ padding: '0.75rem' }}>{u.is_admin ? <span style={{ backgroundColor: '#ffc107', padding: '0.2rem 0.4rem', borderRadius: '3px', fontSize: '0.8rem' }}>Admin</span> : 'User'}</td>
                                                <td style={{ padding: '0.75rem', color: '#666' }}>{formatBytes(u.mailbox_usage_bytes)}</td>
                                                <td style={{ padding: '0.75rem' }}><span style={{ color: 'green' }}>Active</span></td>
                                                <td style={{ padding: '0.75rem' }}>
                                                    {!u.is_admin || u.username !== 'admin' ? (
                                                        <>
                                                            <button
                                                                onClick={() => handleDelete(u.id)}
                                                                style={{ padding: '4px 8px', background: '#fff', border: '1px solid #dc3545', color: '#dc3545', borderRadius: '4px', cursor: 'pointer' }}
                                                            >
                                                                🗑️ Delete
                                                            </button>
                                                            <button
                                                                onClick={() => handlePasswordChange(u.id, u.username)}
                                                                style={{ padding: '4px 8px', background: '#fff', border: '1px solid #17a2b8', color: '#17a2b8', borderRadius: '4px', cursor: 'pointer', marginLeft: '5px' }}
                                                            >
                                                                🔑 Pass
                                                            </button>
                                                        </>
                                                    ) : (
                                                        <>
                                                            <span style={{ color: '#999', fontSize: '0.8rem', marginRight: '5px' }}>Protected</span>
                                                            <button
                                                                onClick={() => handlePasswordChange(u.id, u.username)}
                                                                style={{ padding: '4px 8px', background: '#fff', border: '1px solid #17a2b8', color: '#17a2b8', borderRadius: '4px', cursor: 'pointer' }}
                                                            >
                                                                🔑 Pass
                                                            </button>
                                                        </>
                                                    )}
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        deletedUsers.map(u => (
                                            <tr key={u.id} style={{ borderBottom: '1px solid #dee2e6', backgroundColor: '#fff5f5' }}>
                                                <td style={{ padding: '0.75rem', color: '#888' }}>{u.id}</td>
                                                <td style={{ padding: '0.75rem', color: '#888' }}>{u.username}</td>
                                                <td style={{ padding: '0.75rem', color: '#888' }}>{u.is_admin ? 'Admin' : 'User'}</td>
                                                <td style={{ padding: '0.75rem', color: '#888' }}>{formatBytes(u.mailbox_usage_bytes)}</td>

                                                <td style={{ padding: '0.75rem' }}><span style={{ color: '#dc3545' }}>Deleted</span></td>
                                                <td style={{ padding: '0.75rem', display: 'flex', gap: '5px' }}>
                                                    <button
                                                        onClick={() => handleRestore(u.id)}
                                                        style={{ padding: '4px 8px', background: '#fff', border: '1px solid #28a745', color: '#28a745', borderRadius: '4px', cursor: 'pointer' }}
                                                    >
                                                        ♻️ Restore
                                                    </button>
                                                    <button
                                                        onClick={() => handlePermanentDelete(u.id)}
                                                        style={{ padding: '4px 8px', background: '#dc3545', border: 'none', color: 'white', borderRadius: '4px', cursor: 'pointer' }}
                                                    >
                                                        🔥 Destroy
                                                    </button>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                    {((view === 'active' && users.length === 0) || (view === 'deleted' && deletedUsers.length === 0)) && (
                                        <tr>
                                            <td colSpan={6} style={{ padding: '2rem', textAlign: 'center', color: '#666' }}>
                                                No {view} users found.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            )
            }

            {/* AI Config Tab */}
            {
                tab === 'ai-config' && (
                    <div style={{ maxWidth: '800px', margin: '0 auto' }}>
                        <div style={{ backgroundColor: '#f8f9fa', padding: '2rem', borderRadius: '8px' }}>
                            <h2 style={{ marginBottom: '2rem' }}>⚙️ Configuración de IA para Clasificación</h2>

                            <div style={{ display: 'flex', flexDirection: 'column', gap: '1.5rem' }}>
                                <div>
                                    <label style={{ display: 'block', marginBottom: '0.5rem', fontWeight: 'bold' }}>API URL</label>
                                    <input
                                        style={{ width: '100%', padding: '0.75rem', borderRadius: '4px', border: '1px solid #ced4da' }}
                                        placeholder="https://192.168.1.45/chat/models"
                                        value={aiConfig.api_url}
                                        onChange={e => setAiConfig({ ...aiConfig, api_url: e.target.value })}
                                    />
                                </div>

                                <div>
                                    <label style={{ display: 'block', marginBottom: '0.5rem', fontWeight: 'bold' }}>API Key</label>
                                    <input
                                        type="password"
                                        style={{ width: '100%', padding: '0.75rem', borderRadius: '4px', border: '1px solid #ced4da' }}
                                        placeholder="Dejar vacío para mantener la actual..."
                                        value={aiConfig.api_key}
                                        onChange={e => setAiConfig({ ...aiConfig, api_key: e.target.value })}
                                    />
                                    <small style={{ color: '#6c757d' }}>Opcional. Solo ingresa si quieres cambiar la API Key actual.</small>
                                </div>

                                <div>
                                    <button
                                        onClick={handleTestConnection}
                                        disabled={loadingModels}
                                        style={{
                                            padding: '0.75rem 1.5rem',
                                            backgroundColor: loadingModels ? '#6c757d' : '#17a2b8',
                                            color: 'white',
                                            border: 'none',
                                            borderRadius: '4px',
                                            cursor: loadingModels ? 'not-allowed' : 'pointer',
                                            fontWeight: 'bold'
                                        }}
                                    >
                                        {loadingModels ? '🔄 Conectando...' : '🔌 Probar Conexión y Cargar Modelos'}
                                    </button>
                                </div>

                                {availableModels.length > 0 && (
                                    <>
                                        <div>
                                            <label style={{ display: 'block', marginBottom: '0.5rem', fontWeight: 'bold' }}>Modelo Primario (GPT)</label>
                                            <select
                                                style={{ width: '100%', padding: '0.75rem', borderRadius: '4px', border: '1px solid #ced4da' }}
                                                value={aiConfig.primary_model}
                                                onChange={e => setAiConfig({ ...aiConfig, primary_model: e.target.value })}
                                            >
                                                <option value="">Selecciona un modelo...</option>
                                                {availableModels.map(model => (
                                                    <option key={model} value={model}>{model}</option>
                                                ))}
                                            </select>
                                        </div>

                                        <div>
                                            <label style={{ display: 'block', marginBottom: '0.5rem', fontWeight: 'bold' }}>Modelo Secundario (Qwen)</label>
                                            <select
                                                style={{ width: '100%', padding: '0.75rem', borderRadius: '4px', border: '1px solid #ced4da' }}
                                                value={aiConfig.secondary_model}
                                                onChange={e => setAiConfig({ ...aiConfig, secondary_model: e.target.value })}
                                            >
                                                <option value="">Selecciona un modelo...</option>
                                                {availableModels.map(model => (
                                                    <option key={model} value={model}>{model}</option>
                                                ))}
                                            </select>
                                        </div>

                                        <div>
                                            <button
                                                onClick={handleSaveAIConfig}
                                                style={{
                                                    padding: '0.75rem 2rem',
                                                    backgroundColor: '#28a745',
                                                    color: 'white',
                                                    border: 'none',
                                                    borderRadius: '4px',
                                                    cursor: 'pointer',
                                                    fontWeight: 'bold',
                                                    fontSize: '1rem'
                                                }}
                                            >
                                                💾 Guardar Configuración
                                            </button>
                                        </div>
                                    </>
                                )}
                            </div>
                        </div>
                    </div>
                )
            }

        </div>
    );
};
export default AdminDashboard;
