/**
 * Custom hooks for data fetching using React Query
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import * as api from '../services/api';
import axios from 'axios';
import { getCategories, createCategory, updateCategory, deleteCategory } from '../services/api';

export const streamSync = api.streamSync;

// Accounts hooks
export const useAccounts = () => {
    return useQuery({
        queryKey: ['accounts'],
        queryFn: api.getAccounts,
    });
};

export const useCreateAccount = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: api.createAccount,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['accounts'] });
            queryClient.invalidateQueries({ queryKey: ['messages'] }); // Added invalidation for messages
        },
    });
};

export const useUpdateAccount = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, data }: { id: number; data: any }) => api.updateAccount(id, data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['accounts'] });
        },
    });
};

// Messages hooks
export const useMessages = (params?: {
    account_id?: number;
    classification_label?: string;
    search?: string;
    folder?: string;
    from_email?: string;
    is_starred?: boolean;
    has_attachments?: boolean;
    date_from?: string;
    date_to?: string;
}) => {
    return useQuery({
        queryKey: ['messages', params],
        queryFn: () => api.getMessages(params),
        enabled: true,
    });
};

export const useMessage = (id: string) => {
    return useQuery({
        queryKey: ['message', id],
        queryFn: () => api.getMessage(id),
        enabled: !!id,
    });
};

export const useUpdateMessage = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, data }: { id: string; data: any }) =>
            api.updateMessage(id, data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['messages'] });
        },
    });
};

// Sync hooks
export const useStartSync = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: api.startSync,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['messages'] });
        },
    });
};

// Update classification manually
export const useUpdateClassification = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ messageId, label }: { messageId: string; label: string | null }) =>
            api.updateClassification(messageId, label),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['messages'] });
        },
    });
};

// Classification hooks
export const useClassifyMessage = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (messageId: string) => {
            const response = await axios.post(`http://localhost:8000/api/classify/${messageId}`);
            return response.data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['messages'] });
        },
    });
};

// Mark as read hook
export const useMarkAsRead = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ messageId, isRead }: { messageId: string; isRead: boolean }) => {
            const response = await axios.patch(
                `http://localhost:8000/api/messages/${messageId}/read`,
                null,
                { params: { is_read: isRead } }
            );
            return response.data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['messages'] });
        },
    });
};

// Delete message hook
export const useDeleteMessage = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (messageId: string) => {
            await axios.delete(`http://localhost:8000/api/messages/${messageId}`);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['messages'] });
        },
    });
};

// Bulk mark as read hook
export const useBulkMarkAsRead = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ accountId, classificationLabel }: { accountId: number; classificationLabel?: string }) => {
            const response = await axios.patch(
                `http://localhost:8000/api/messages/bulk/read`,
                null,
                {
                    params: {
                        account_id: accountId,
                        classification_label: classificationLabel,
                        is_read: true
                    }
                }
            );
            return response.data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['messages'] });
        },
    });
};

// Toggle star hook
export const useToggleStar = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ messageId, isStarred }: { messageId: string; isStarred: boolean }) => {
            const response = await axios.patch(
                `http://localhost:8000/api/messages/${messageId}/star`,
                null,
                { params: { is_starred: isStarred } }
            );
            return response.data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['messages'] });
        },
    });
};

// Move to folder hook
export const useMoveToFolder = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ messageId, folder }: { messageId: string; folder: string }) => {
            const response = await axios.patch(
                `http://localhost:8000/api/messages/${messageId}/folder`,
                null,
                { params: { folder } }
            );
            return response.data;
        },
    });
};

// Categories Hooks

export const useCategories = () => {
    return useQuery({
        queryKey: ['categories'],
        queryFn: getCategories,
        staleTime: Infinity // Categories change rarely unless manually updated
    });
};

export const useCreateCategory = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: createCategory,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['categories'] });
        },
    });
};

export const useUpdateCategory = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: ({ id, data }: { id: number; data: any }) => updateCategory(id, data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['categories'] });
        },
    });
};

export const useDeleteCategory = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: deleteCategory,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['categories'] });
        },
    });
};

// Empty folder / Bulk delete hook
export const useEmptyFolder = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ accountId, folder, classificationLabel }: { accountId: number; folder?: string; classificationLabel?: string }) => {
            await axios.delete(`http://localhost:8000/api/messages/bulk`, {
                params: {
                    account_id: accountId,
                    folder,
                    classification_label: classificationLabel
                }
            });
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['messages'] });
        },
    });
};
