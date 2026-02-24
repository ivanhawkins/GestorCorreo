/**
 * Extended API client with message body and attachments
 * Uses the authenticated apiClient from api.ts (includes Bearer token interceptor)
 */
import { apiClient } from './api';

// En producción sin VITE_API_URL se usan rutas relativas (mismo origen)
const API_BASE_URL = import.meta.env.VITE_API_URL || '';

// Message Body
export interface MessageBody {
    body_text?: string;
    body_html?: string;
}

export const getMessageBody = async (messageId: string): Promise<MessageBody> => {
    const response = await apiClient.get(`/api/messages/${messageId}/body`);
    return response.data;
};

// Attachments
export interface Attachment {
    id: number;
    filename: string;
    mime_type?: string;
    size_bytes: number;
}

export const getMessageAttachments = async (messageId: string): Promise<Attachment[]> => {
    const response = await apiClient.get(`/api/attachments/message/${messageId}`);
    return response.data;
};

export const getAttachmentDownloadUrl = (attachmentId: number): string => {
    return `${API_BASE_URL}/api/attachments/${attachmentId}`;
};

// Export all previous interfaces and functions (including getMessage, Message, MessageDetail, etc.)
export * from './api';
