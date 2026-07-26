import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api, getToken, setToken } from './api';

// Holds the authenticated user (with roles + permissions) and exposes helpers
// the whole app uses to gate UI and sign in/out. Permissions come straight
// from the API so the client never guesses.

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);

    const loadMe = useCallback(async () => {
        if (!getToken()) {
            setUser(null);
            setLoading(false);
            return;
        }
        try {
            const { data } = await api.get('/auth/me');
            setUser(data);
        } catch {
            setToken(null);
            setUser(null);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadMe();
    }, [loadMe]);

    const login = useCallback(async (email, password) => {
        const { data } = await api.post('/auth/login', { email, password, device_name: 'web' });
        setToken(data.token);
        setUser(data.user);
        return data.user;
    }, []);

    const logout = useCallback(async () => {
        try {
            await api.post('/auth/logout');
        } catch {
            // ignore — we clear locally regardless
        }
        setToken(null);
        setUser(null);
    }, []);

    const can = useCallback(
        (permission) => !!user && (user.is_super_admin || (user.permissions || []).includes(permission)),
        [user],
    );

    const value = useMemo(
        () => ({ user, loading, login, logout, can, refresh: loadMe }),
        [user, loading, login, logout, can, loadMe],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
    const ctx = useContext(AuthContext);
    if (!ctx) throw new Error('useAuth must be used within an AuthProvider');
    return ctx;
}
