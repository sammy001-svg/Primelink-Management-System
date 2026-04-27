'use client';

import DashboardLayout from '@/components/DashboardLayout';
import { 
  BarChart3, Building2, Users, Wrench, 
  TrendingUp, Calendar as CalendarIcon, 
  ChevronRight, ArrowUpRight, CheckCircle2,
  Clock, MapPin
} from 'lucide-react';
import { mockRevenueData, mockCalendarEvents } from '@/lib/mock-data';
import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { supabase } from '@/lib/supabase';
import TenantDashboard from '@/components/TenantDashboard';
import LandlordDashboard from '@/components/LandlordDashboard';

export default function Home() {
  const router = useRouter();
  const [selectedDate, setSelectedDate] = useState(new Date().toISOString().split('T')[0]);
  const [role, setRole] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const checkUser = async () => {
      const { data: { session } } = await supabase.auth.getSession();
      if (!session) {
        router.push('/login');
        return;
      }
      setRole(session.user.user_metadata?.role || 'tenant');
      setLoading(false);
    };
    checkUser();
  }, [router]);

  if (loading) {
    return (
      <DashboardLayout>
        <div className="flex items-center justify-center min-h-[60vh]">
          <div className="w-10 h-10 border-4 border-accent-gold border-t-transparent rounded-full animate-spin"></div>
        </div>
      </DashboardLayout>
    );
  }

  if (role === 'tenant') {
    return (
      <DashboardLayout>
        <TenantDashboard />
      </DashboardLayout>
    );
  }

  if (role === 'landlord') {
    return (
      <DashboardLayout>
        <LandlordDashboard />
      </DashboardLayout>
    );
  }

  const stats = [
    { label: 'Total Properties', value: '124', icon: Building2, color: 'text-blue-500', bg: 'bg-blue-50 dark:bg-blue-900/20' },
    { label: 'Active Tenants', value: '89', icon: Users, color: 'text-green-500', bg: 'bg-green-50 dark:bg-green-900/20' },
    { label: 'Revenue (MTD)', value: 'KSh 45,200', icon: BarChart3, color: 'text-accent-gold', bg: 'bg-amber-50 dark:bg-amber-900/20' },
    { label: 'Pending Maintenance', value: '12', icon: Wrench, color: 'text-orange-500', bg: 'bg-orange-50 dark:bg-orange-900/20' },
  ];

  const filteredEvents = mockCalendarEvents.filter(ev => ev.date === '2024-03-12'); // Using a static date for demo

  return (
    <DashboardLayout>
      <div className="space-y-8 animate-in fade-in duration-500">
        {/* Top Stats */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
          {stats.map((stat) => (
            <div key={stat.label} className="glass-card p-6 flex items-center gap-4 hover:translate-y-[-2px] transition-transform cursor-pointer group">
              <div className={`p-3 rounded-xl ${stat.bg} ${stat.color} group-hover:scale-110 transition-transform`}>
                <stat.icon size={24} />
              </div>
              <div>
                <p className="text-xs font-black text-slate-400 uppercase tracking-widest">{stat.label}</p>
                <h3 className="text-2xl font-black text-slate-900 dark:text-white">{stat.value}</h3>
              </div>
            </div>
          ))}
        </div>

        {/* Main Dashboard Grid */}
        <div className="grid grid-cols-1 xl:grid-cols-3 gap-8">
          
          {/* Recent Activity & System Update */}
          <div className="xl:col-span-2 space-y-8">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
              {/* Recent Activity */}
              <div className="glass-card p-8">
                <div className="flex justify-between items-center mb-6">
                  <h3 className="text-xl font-black text-slate-900 dark:text-white flex items-center gap-2">
                    <HistoryIcon className="text-accent-gold" size={20} /> Recent Activity
                  </h3>
                  <button className="text-xs font-black text-slate-400 hover:text-accent-gold transition-colors uppercase tracking-widest">View All</button>
                </div>
                <div className="space-y-4">
                  {[
                    { text: 'New lease signed for Elysian Heights', time: '2 hours ago', icon: Users, color: 'text-blue-500' },
                    { text: 'Maintenance resolved at Skyline Hub', time: '4 hours ago', icon: CheckCircle2, color: 'text-green-500' },
                    { text: 'Payment received from David Kamau', time: 'Yesterday', icon: DollarSignIcon, color: 'text-accent-gold' },
                  ].map((activity, i) => (
                    <div key={i} className="flex gap-4 items-start pb-4 border-b border-slate-100 dark:border-slate-800 last:border-0 last:pb-0 group cursor-pointer">
                      <div className={`w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center ${activity.color} group-hover:bg-slate-900 group-hover:text-white dark:group-hover:bg-white dark:group-hover:text-slate-900 transition-all`}>
                        <activity.icon size={18} />
                      </div>
                      <div className="flex-1">
                        <p className="text-sm font-bold text-slate-900 dark:text-white group-hover:text-accent-gold transition-colors">{activity.text}</p>
                        <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">{activity.time}</p>
                      </div>
                      <ChevronRight size={14} className="text-slate-300 opacity-0 group-hover:opacity-100 transition-all" />
                    </div>
                  ))}
                </div>
              </div>

              {/* System Update / Promo */}
              <div className="glass-card p-8 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 border-0 shadow-2xl overflow-hidden relative group">
                <div className="relative z-10 h-full flex flex-col justify-between">
                  <div>
                    <div className="flex items-center gap-2 mb-4">
                      <span className="px-2 py-1 bg-accent-gold/20 text-accent-gold rounded text-[10px] font-black uppercase tracking-widest">Enterprise</span>
                      <span className="text-[10px] font-black opacity-60 uppercase tracking-widest">v2.0.1</span>
                    </div>
                    <h3 className="text-2xl font-black mb-3 leading-tight">Intelligent Payouts Are Now Live</h3>
                    <p className="text-slate-400 dark:text-slate-500 text-sm font-medium leading-relaxed">Automate your landlord distributions with precision tracking and instant digital vouchers.</p>
                  </div>
                  <button className="w-full mt-6 py-3 bg-accent-gold text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest hover:translate-y-[-2px] transition-all shadow-lg">
                    Setup Now
                  </button>
                </div>
                <div className="absolute top-[-20%] right-[-10%] w-64 h-64 bg-accent-gold/20 rounded-full blur-3xl group-hover:scale-110 transition-transform duration-1000"></div>
              </div>
            </div>

            {/* Analytics Section */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
               {/* Occupancy Trends */}
               <div className="glass-card p-8">
                  <div className="flex justify-between items-center mb-10">
                    <div>
                      <h3 className="text-lg font-black text-slate-900 dark:text-white">Occupancy Trends</h3>
                      <p className="text-xs text-slate-500 font-bold uppercase tracking-widest">Last 6 Months</p>
                    </div>
                    <div className="text-right">
                       <span className="text-2xl font-black text-green-500 flex items-center gap-1 justify-end">
                         <ArrowUpRight size={20} /> 94%
                       </span>
                       <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Avg. Growth</p>
                    </div>
                  </div>
                  <div className="h-48 flex items-end justify-between gap-4">
                    {mockRevenueData.map((data, idx) => (
                      <div key={idx} className="flex-1 flex flex-col items-center gap-3 group h-full">
                        <div className="flex-1 w-full flex items-end">
                          <div 
                            className="w-full bg-slate-100 dark:bg-slate-800 rounded-xl transition-all group-hover:bg-accent-gold group-hover:translate-y-[-4px]" 
                            style={{ height: `${(data.amount / 60000) * 100}%` }}
                          ></div>
                        </div>
                        <span className="text-[10px] font-black text-slate-400 uppercase tracking-tighter opacity-0 group-hover:opacity-100 transition-opacity">{data.month}</span>
                      </div>
                    ))}
                  </div>
               </div>

               {/* Payment Rate */}
               <div className="glass-card p-8 flex flex-col items-center justify-center text-center">
                  <h3 className="text-lg font-black text-slate-900 dark:text-white mb-2 self-start">Rent Payment Rate</h3>
                  <div className="relative w-48 h-48 mb-6 mt-4">
                    <svg className="w-full h-full transform -rotate-90">
                      <circle
                        cx="96"
                        cy="96"
                        r="80"
                        stroke="currentColor"
                        strokeWidth="16"
                        fill="transparent"
                        className="text-slate-100 dark:text-slate-900"
                      />
                      <circle
                        cx="96"
                        cy="96"
                        r="80"
                        stroke="currentColor"
                        strokeWidth="16"
                        strokeDasharray={502}
                        strokeDashoffset={502 * (1 - 0.88)}
                        strokeLinecap="round"
                        fill="transparent"
                        className="text-accent-gold"
                      />
                    </svg>
                    <div className="absolute inset-0 flex flex-col items-center justify-center">
                      <span className="text-4xl font-black text-slate-900 dark:text-white">88%</span>
                      <span className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Collected</span>
                    </div>
                  </div>
                  <div className="flex gap-4 w-full">
                    <div className="flex-1 p-3 bg-slate-50 dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800">
                      <p className="text-[10px] font-black text-slate-400 uppercase mb-1">Paid</p>
                      <p className="text-sm font-black text-slate-900 dark:text-white">KSh 1.2M</p>
                    </div>
                    <div className="flex-1 p-3 bg-slate-50 dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800">
                      <p className="text-[10px] font-black text-slate-400 uppercase mb-1">Pending</p>
                      <p className="text-sm font-black text-orange-500">KSh 142K</p>
                    </div>
                  </div>
               </div>
            </div>
          </div>

          {/* Activity Calendar Sidebar */}
          <div className="space-y-6">
            <div className="glass-card p-8 bg-linear-to-b from-white to-slate-50 dark:from-slate-900 dark:to-slate-950">
               <div className="flex items-center justify-between mb-8">
                 <h3 className="text-xl font-black text-slate-900 dark:text-white flex items-center gap-2">
                   <CalendarIcon size={20} className="text-accent-gold" /> Calendar
                 </h3>
                 <span className="text-[10px] font-black text-slate-400 uppercase tracking-widest">March 2024</span>
               </div>

               {/* Calendar Mock Grid */}
               <div className="grid grid-cols-7 gap-1 mb-8">
                 {['S','M','T','W','T','F','S'].map((day, idx) => (
                   <div key={`${day}-${idx}`} className="text-center text-[10px] font-black text-slate-400 py-2">{day}</div>
                 ))}
                 {Array.from({ length: 31 }).map((_, i) => {
                   const day = i + 1;
                   const isToday = day === 12; // Demo current day matches mock events
                   return (
                     <div 
                       key={i} 
                       className={`aspect-square flex items-center justify-center text-xs font-bold rounded-lg cursor-pointer transition-all ${
                         isToday 
                           ? 'bg-accent-gold text-white shadow-lg' 
                           : 'hover:bg-slate-100 dark:hover:bg-slate-800'
                       }`}
                     >
                       {day}
                     </div>
                   );
                 })}
               </div>

               <div className="space-y-4">
                 <h4 className="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 dark:border-slate-800 pb-2">Schedule for Mar 12</h4>
                 {filteredEvents.length > 0 ? filteredEvents.map(event => (
                   <div key={event.id} className="p-4 bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 hover:border-accent-gold/50 transition-all cursor-pointer group">
                      <div className="flex justify-between items-start mb-2">
                        <span className={`px-2 py-0.5 rounded text-[8px] font-black uppercase tracking-tighter ${
                          event.type === 'Inspection' ? 'bg-blue-100 text-blue-600' :
                          event.type === 'Maintenance' ? 'bg-orange-100 text-orange-600' :
                          'bg-green-100 text-green-600'
                        }`}>
                          {event.type}
                        </span>
                        <div className="flex items-center gap-1 text-[10px] font-bold text-slate-400">
                          <Clock size={10} /> {event.time}
                        </div>
                      </div>
                      <p className="text-sm font-bold text-slate-900 dark:text-white mb-2 group-hover:text-accent-gold transition-colors">{event.title}</p>
                      <div className="flex items-center gap-1 text-[10px] font-bold text-slate-500">
                        <MapPin size={10} /> {event.location}
                      </div>
                   </div>
                 )) : (
                   <div className="text-center py-8 text-slate-400 text-xs font-medium">No appointments for today</div>
                 )}
                 <button className="w-full py-3 bg-slate-100 dark:bg-slate-800 text-slate-500 rounded-xl font-black text-[10px] uppercase tracking-widest hover:bg-slate-900 hover:text-white transition-all">
                    + Book Appointment
                 </button>
               </div>
            </div>

            {/* Help Card */}
            <div className="glass-card p-6 border-accent-gold/20 bg-accent-gold/5">
                <p className="text-xs font-bold text-slate-900 dark:text-white leading-relaxed">
                  Need help managing your portfolio? Our executive support is available 24/7.
                </p>
                <button className="text-[10px] font-black text-accent-gold uppercase tracking-widest mt-3 flex items-center gap-1 hover:gap-2 transition-all">
                  Contact Support <ChevronRight size={12} />
                </button>
            </div>
          </div>
        </div>
      </div>
    </DashboardLayout>
  );
}

// Add missing icon components
function HistoryIcon({ size = 20, className = "" }) {
  return (
    <svg 
      width={size} 
      height={size} 
      viewBox="0 0 24 24" 
      fill="none" 
      stroke="currentColor" 
      strokeWidth="2" 
      strokeLinecap="round" 
      strokeLinejoin="round" 
      className={className}
    >
      <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
      <path d="M3 3v5h5" />
      <path d="m12 7 0 5 3 3" />
    </svg>
  );
}

function DollarSignIcon({ size = 20, className = "" }) {
  return (
    <svg 
      width={size} 
      height={size} 
      viewBox="0 0 24 24" 
      fill="none" 
      stroke="currentColor" 
      strokeWidth="2" 
      strokeLinecap="round" 
      strokeLinejoin="round" 
      className={className}
    >
      <line x1="12" x2="12" y1="2" y2="22" />
      <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
    </svg>
  );
}
