'use client';

import { useState } from 'react';
import { Bell, X, Info, AlertTriangle, CheckCircle2, Clock } from 'lucide-react';

export interface Notification {
  id: string;
  title: string;
  message: string;
  type: 'info' | 'warning' | 'success' | 'urgent';
  time: string;
  read: boolean;
}

const mockNotifications: Notification[] = [
  { id: '1', title: 'Lease Expiring', message: 'The lease for Elysian Heights Luxury Villa expires in 30 days.', type: 'warning', time: '2h ago', read: false },
  { id: '2', title: 'Maintenance Update', message: 'HVAC repair for Skyline Business Hub has been resolved.', type: 'success', time: '4h ago', read: false },
  { id: '3', title: 'New Payment', message: 'Rent payment received from Johnathan Davis (KSh 4,500).', type: 'info', time: '1d ago', read: true },
  { id: '4', title: 'Emergency Request', message: 'Plumbing emergency reported at Azure Bay Apartment.', type: 'urgent', time: '2d ago', read: true },
];

export default function NotificationCenter() {
  const [isOpen, setIsOpen] = useState(false);
  const [notifications, setNotifications] = useState(mockNotifications);

  const unreadCount = notifications.filter(n => !n.read).length;

  const getIcon = (type: string) => {
    switch (type) {
      case 'warning': return <AlertTriangle className="text-amber-500" size={18} />;
      case 'success': return <CheckCircle2 className="text-green-500" size={18} />;
      case 'urgent': return <Clock className="text-red-500" size={18} />;
      default: return <Info className="text-blue-500" size={18} />;
    }
  };

  return (
    <div className="relative">
      <button 
        onClick={() => setIsOpen(!isOpen)}
        className="p-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-500 hover:text-accent-gold transition-colors relative"
      >
        <Bell size={20} />
        {unreadCount > 0 && (
          <div className="absolute top-2 right-2 w-2 h-2 bg-red-500 rounded-full border-2 border-white dark:border-slate-900"></div>
        )}
      </button>

      {isOpen && (
        <>
          <div className="fixed inset-0 z-40" onClick={() => setIsOpen(false)}></div>
          <div className="absolute right-0 mt-3 w-80 sm:w-96 z-50 animate-in fade-in slide-in-from-top-2 duration-200">
            <div className="glass-card bg-white dark:bg-slate-900 shadow-2xl overflow-hidden">
              <div className="p-4 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center bg-slate-50/50 dark:bg-slate-900/50">
                <h3 className="font-bold text-slate-900 dark:text-white flex items-center gap-2">
                  Notifications
                  {unreadCount > 0 && (
                    <span className="px-1.5 py-0.5 bg-accent-gold text-white text-[10px] rounded-md font-black">
                      {unreadCount} NEW
                    </span>
                  )}
                </h3>
                <button onClick={() => setIsOpen(false)} className="text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors">
                  <X size={18} />
                </button>
              </div>

              <div className="max-h-[400px] overflow-y-auto custom-scrollbar">
                {notifications.length > 0 ? (
                  <div className="divide-y divide-slate-100 dark:divide-slate-800">
                    {notifications.map((n) => (
                      <div 
                        key={n.id} 
                        className={`p-4 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors cursor-pointer group ${!n.read ? 'bg-accent-gold/5 dark:bg-accent-gold/5' : ''}`}
                        onClick={() => {
                          setNotifications(notifications.map(notif => notif.id === n.id ? { ...notif, read: true } : notif));
                        }}
                      >
                        <div className="flex gap-3">
                          <div className="mt-1 shrink-0">{getIcon(n.type)}</div>
                          <div className="space-y-1 overflow-hidden">
                            <div className="flex justify-between gap-2">
                              <p className={`text-sm font-bold truncate ${!n.read ? 'text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400'}`}>
                                {n.title}
                              </p>
                              <span className="text-[10px] font-bold text-slate-400 shrink-0 uppercase">{n.time}</span>
                            </div>
                            <p className="text-xs text-slate-500 line-clamp-2 leading-relaxed font-medium">
                              {n.message}
                            </p>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="py-12 text-center space-y-2">
                    <Bell className="mx-auto text-slate-200 dark:text-slate-800" size={48} />
                    <p className="text-sm font-bold text-slate-400">All caught up!</p>
                  </div>
                )}
              </div>

              <div className="p-3 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 text-center">
                <button className="text-[10px] font-black text-accent-gold uppercase tracking-[0.2em] hover:opacity-80 transition-opacity">
                  View All Activity
                </button>
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
