/**
 * API client - Extended with new endpoints
 */
import axios from 'axios';

// En producción sin VITE_API_URL se usan rutas relativas (mismo origen)
const API_BASE_URL = import.meta.env.VITE_API_URL || '';

export const apiClient = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
  },
  timeout: 30000,
});

const TOKEN_KEY = 'auth_token';
export const setToken = (token: string) => localStorage.setItem(TOKEN_KEY, token);
export const getToken = () => localStorage.getItem(TOKEN_KEY);
export const removeToken = () => localStorage.removeItem(TOKEN_KEY);

apiClient.interceptors.request.use((config) => {
  const token = getToken();
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Health check
export const healthCheck = async () => {
  const response = await apiClient.get('/health');
  return response.data;
};

// Accounts
export interface Account {
  id: number;
  user_id: number;
  email_address: string;
  imap_host: string;
  imap_port: number;
  smtp_host: string;
  smtp_port: number;
  username: string;
  is_active: boolean;
  created_at: string;
  updated_at: string;
  auto_classify?: boolean;
  auto_sync_interval?: number;
  custom_classification_prompt?: string;
  custom_review_prompt?: string;
  owner_profile?: string; // User persona
  is_deleted: boolean;
  protocol: 'imap' | 'pop3';
  mailbox_storage_bytes?: number;
  mailbox_storage_limit?: number;
}

export interface AccountCreate {
  email_address: string;
  imap_host: string;
  imap_port: number;
  smtp_host: string;
  smtp_port: number;
  username: string;
  password: string;
  protocol?: 'imap' | 'pop3';
}

export const getAccounts = async (deleted: boolean = false): Promise<Account[]> => {
  const response = await apiClient.get('/api/accounts/', { params: { deleted } });
  return response.data;
};

export const deleteAccount = async (id: number, permanent: boolean = false) => {
  await apiClient.delete(`/api/accounts/${id}`, { params: { permanent } });
};

export const restoreAccount = async (id: number) => {
  const response = await apiClient.post(`/api/accounts/${id}/restore`);
  return response.data;
};

export interface Message {
  id: string;
  account_id: number;
  from_email: string;
  from_name?: string;
  subject?: string;
  date: string;
  snippet?: string;
  is_read: boolean;
  is_starred: boolean;
  has_attachments: boolean;
  classification_label?: string;
  folder: string;
}

export interface MessageDetail extends Message {
  to_addresses: string;
  cc_addresses: string;
  bcc_addresses?: string;
  body_text?: string;
  body_html?: string;
  message_id: string;
  attachments?: Array<{
    id: number;
    filename: string;
    mime_type?: string;
    size_bytes: number;
  }>;
}


export const createAccount = async (data: AccountCreate): Promise<Account> => {
  const response = await apiClient.post('/api/accounts/', data);
  return response.data;

};

export const updateAccount = async (id: number, data: Partial<AccountCreate> & { auto_classify?: boolean }): Promise<Account> => {
  const response = await apiClient.put(`/api/accounts/${id}`, data);
  return response.data;
};

export const testAccountConnection = async (id: number): Promise<{ success: boolean; message?: string; error?: string; folders?: string[] }> => {
  const response = await apiClient.post(`/api/accounts/${id}/test-connection`);
  return response.data;
};

// --- Admin: gestión de cuentas ---
export interface AccountWithUser extends Account {
  user?: { id: number; username: string };
}

export const getAdminAccounts = async (deleted: boolean = false): Promise<AccountWithUser[]> => {
  const response = await apiClient.get('/api/admin/accounts', { params: { deleted } });
  return response.data;
};

export interface AdminAccountCreate extends AccountCreate {
  user_id: number;
}

export const createAccountForUser = async (data: AdminAccountCreate): Promise<Account> => {
  const response = await apiClient.post('/api/admin/accounts', data);
  return response.data.account;
};

export const deleteAdminAccount = async (id: number) => {
  await apiClient.delete(`/api/admin/accounts/${id}`);
};

export const getMessages = async (params?: {
  account_id?: number;
  folder?: string;
  classification_label?: string;
  search?: string;
}): Promise<Message[]> => {
  const response = await apiClient.get('/api/messages/', { params });
  return response.data;
};

export const toggleStar = async (messageId: string, isStarred: boolean): Promise<{ updated: number }> => {
  const response = await apiClient.put(`/api/messages/${messageId}/flags`, { is_read: undefined, is_starred: isStarred });
  return response.data;
};

export const getMessage = async (id: string): Promise<MessageDetail> => {
  const response = await apiClient.get(`/api/messages/${id}`);
  return response.data;
};

export const updateMessage = async (id: string, data: { is_read?: boolean; is_starred?: boolean }) => {
  const response = await apiClient.patch(`/api/messages/${id}`, data);
  return response.data;
};

export const deleteMessage = async (id: string) => {
  const response = await apiClient.delete(`/api/messages/${id}`);
  return response.data;
};

// --- Classification ---
export interface Classification {
  id: number;
  message_id: string;
  gpt_label?: string;
  gpt_confidence?: number;
  gpt_rationale?: string;
  qwen_label?: string;
  qwen_confidence?: number;
  qwen_rationale?: string;
  final_label: string;
  final_reason?: string;
  decided_by: string;
  decided_at: string;
}

export const getClassification = async (messageId: string): Promise<Classification> => {
  const response = await apiClient.get(`/api/classifications/${messageId}`);
  return response.data;
};

export const updateClassification = async (messageId: string, classificationLabel: string | null): Promise<{ updated: number }> => {
  const response = await apiClient.put(`/api/messages/${messageId}/classify`, { classification_label: classificationLabel });
  return response.data;
};

export const classifyMessage = async (messageId: string): Promise<any> => {
  const response = await apiClient.post(`/api/classify/${messageId}`);
  return response.data;
};

export const classifyPendingMessages = async (accountId: number): Promise<{ status: string, message: string, classified: number, total_processed: number }> => {
  const response = await apiClient.post(`/api/classify/pending/${accountId}`);
  return response.data;
};

// Sync
export interface SyncRequest {
  account_id: number;
  folder?: string;
  auto_classify?: boolean;  // Automatically classify new messages after sync
}

export const startSync = async (data: SyncRequest) => {
  const response = await apiClient.post('/api/sync/start/', data);
  return response.data;
};

export const getSyncStatus = async () => {
  const response = await apiClient.get('/api/sync/status');
  return response.data;
};

export const streamSync = async (
  data: SyncRequest,
  onMessage: (data: any) => void,
  onError: (error: any) => void
) => {
  try {
    const token = getToken();
    const headers: HeadersInit = {
      'Content-Type': 'application/json',
    };

    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    const response = await fetch(`${API_BASE_URL}/api/sync/stream`, {
      method: 'POST',
      headers,
      body: JSON.stringify(data),
    });

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    const reader = response.body?.getReader();
    const decoder = new TextDecoder();

    if (!reader) throw new Error('No reader available');

    let buffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      const chunk = decoder.decode(value, { stream: true });
      buffer += chunk;

      const lines = buffer.split('\n\n');
      // Keep the last part if it's incomplete
      buffer = lines.pop() || '';

      for (const line of lines) {
        if (line.trim().startsWith('data: ')) {
          const jsonStr = line.trim().slice(6);
          if (!jsonStr) continue;
          try {
            const parsed = JSON.parse(jsonStr);
            onMessage(parsed);
          } catch (e) {
            console.error('Error parsing SSE', e);
          }
        }
      }
    }

    // Procesar lo que quede en el buffer al cerrar el stream
    if (buffer.trim().startsWith('data: ')) {
      const jsonStr = buffer.trim().slice(6);
      if (jsonStr) {
        try {
          const parsed = JSON.parse(jsonStr);
          onMessage(parsed);
        } catch (e) {
          console.error('Error parsing SSE final chunk', e);
        }
      }
    }
  } catch (err) {
    onError(err);
  }
};



// Categories
export interface Category {
  id: number;
  key: string;
  name: string;
  description?: string;
  ai_instruction: string;
  icon?: string;
  is_system: boolean;
}

export interface CategoryCreate {
  key: string;
  name: string;
  description?: string;
  ai_instruction: string;
  icon?: string;
}

export interface CategoryUpdate {
  name?: string;
  description?: string;
  ai_instruction?: string;
  icon?: string;
}

// Mock storage for session capabilities
let MOCK_CATEGORIES: Category[] = [
  { id: 1, key: 'Interesantes', name: 'Interesantes', ai_instruction: 'Budget requests', is_system: true, icon: '⭐' },
  { id: 2, key: 'SPAM', name: 'SPAM', ai_instruction: 'Unwanted', is_system: true, icon: '🚫' },
  { id: 3, key: 'EnCopia', name: 'EnCopia', ai_instruction: 'CCed emails', is_system: true, icon: '👥' },
  { id: 4, key: 'Servicios', name: 'Servicios', ai_instruction: 'Transactional', is_system: true, icon: '⚙️' }
];

export const getCategories = async (): Promise<Category[]> => {
  try {
    const response = await apiClient.get('/api/categories/');
    return response.data;
  } catch (error) {
    console.warn('Backend unavailable. Returning mock categories.');
    return [...MOCK_CATEGORIES];
  }
};

export const createCategory = async (data: CategoryCreate): Promise<Category> => {
  try {
    const response = await apiClient.post('/api/categories/', data);
    return response.data;
  } catch (error) {
    console.warn('Backend unavailable. Mocking category creation.');
    const newCategory: Category = {
      id: Date.now(),
      ...data,
      is_system: false
    };
    MOCK_CATEGORIES.push(newCategory);
    return newCategory;
  }
};

export const updateCategory = async (id: number, data: CategoryUpdate): Promise<Category> => {
  try {
    const response = await apiClient.put(`/api/categories/${id}`, data);
    return response.data;
  } catch (error) {
    console.warn('Backend unavailable. Mocking category update.');
    const index = MOCK_CATEGORIES.findIndex(c => c.id === id);
    if (index !== -1) {
      MOCK_CATEGORIES[index] = { ...MOCK_CATEGORIES[index], ...data };
      return MOCK_CATEGORIES[index];
    }
    throw new Error('Category not found');
  }
};

export const deleteCategory = async (id: number) => {
  try {
    const response = await apiClient.delete(`/api/categories/${id}`);
    return response.data;
  } catch (error) {
    console.warn('Backend unavailable. Mocking category deletion.');
    MOCK_CATEGORIES = MOCK_CATEGORIES.filter(c => c.id !== id);
    return { success: true };
  }
};

// Auth
export interface User {
  id: number;
  username: string;
  is_active: boolean;
  is_admin: boolean;
  mailbox_usage_bytes?: number;
}


export const login = async (username: string, password: string): Promise<{ access_token: string }> => {
  const params = new URLSearchParams();
  params.append('username', username);
  params.append('password', password);

  const response = await apiClient.post('/api/auth/token', params, {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
  });
  return response.data;
};

export const getCurrentUser = async (): Promise<User> => {
  const response = await apiClient.get('/api/auth/users/me');
  return response.data;
};

export const getUsers = async (deleted: boolean = false): Promise<User[]> => {
  const response = await apiClient.get('/api/users/', { params: { deleted } });
  return response.data;
};

export const createUser = async (data: any): Promise<User> => {
  const response = await apiClient.post('/api/users/', data);
  return response.data;
};

export const deleteUser = async (id: number, permanent: boolean = false) => {
  await apiClient.delete(`/api/users/${id}`, { params: { permanent } });
};

export const restoreUser = async (id: number) => {
  const response = await apiClient.post(`/api/users/${id}/restore`);
  return response.data;
};

export const updateUserPassword = async (userId: number, password: string) => {
  const response = await apiClient.put(`/api/users/${userId}/password`, { password });
  return response.data;
};

// ============================================
// AI CONFIGURATION  
// ============================================

export interface AIConfig {
  api_url: string;
  primary_model: string;
  secondary_model: string;
}

export interface AIConfigUpdate extends AIConfig {
  api_key: string;
}

export const getAIConfig = async (): Promise<AIConfig> => {
  const response = await apiClient.get('/api/ai-config');
  return response.data;
};

export const updateAIConfig = async (config: AIConfigUpdate) => {
  const response = await apiClient.put('/api/ai-config', config);
  return response.data;
};

export const getAvailableModels = async (): Promise<string[]> => {
  const response = await apiClient.get('/api/ai-config/models');
  return response.data.models;
};

// AI Status & Generation
export const getAiStatus = async (): Promise<{ available: boolean; model?: string; reason?: string }> => {
  const response = await apiClient.get('/api/ai/status');
  return response.data;
};

export const testAiConnection = async (apiUrl: string, apiKey: string): Promise<{ success: boolean; models?: string[]; error?: string }> => {
  const response = await apiClient.post('/api/ai/test', { api_url: apiUrl, api_key: apiKey });
  return response.data;
};

// Resync message bodies (for messages synced without body content)
export const resyncMessageBodies = async (accountId: number): Promise<{ updated: number; failed: number; total: number; message: string }> => {
  const response = await apiClient.post('/api/sync/resync-bodies', null, {
    params: { account_id: accountId }
  });
  return response.data;
};

// Resync message attachments (for messages with has_attachments=true but no attachment records)
export const resyncMessageAttachments = async (accountId: number): Promise<{ updated: number; failed: number; total: number; message: string }> => {
  const response = await apiClient.post('/api/sync/resync-attachments', null, {
    params: { account_id: accountId }
  });
  return response.data;
};


