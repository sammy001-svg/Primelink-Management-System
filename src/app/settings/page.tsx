'use client';

import DashboardLayout from '@/components/DashboardLayout';
import { 
  Settings, 
  Globe, 
  Palette, 
  ShieldCheck, 
  Users, 
  Save, 
  Upload, 
  Smartphone,
  CheckCircle2,
  Lock,
  Mail,
  Phone,
  Building2,
  ChevronRight
} from 'lucide-react';
import { supabase } from '@/lib/supabase';
import { useState, useEffect } from 'react';

type TabType = 'profile' | 'general' | 'branding' | 'permissions' | 'security';

export default function SettingsPage() {
  const [activeTab, setActiveTab] = useState<TabType>('profile');
  const [role, setRole] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);
  const [showSuccess, setShowSuccess] = useState(false);

  useEffect(() => {
    async function getRole() {
      const { data: { session } } = await supabase.auth.getSession();
      if (session?.user) {
        setRole(session.user.user_metadata?.role || 'tenant');
      }
    }
    getRole();
  }, []);

  const handleSave = () => {
    setIsSaving(true);
    setTimeout(() => {
      setIsSaving(false);
      setShowSuccess(true);
      setTimeout(() => setShowSuccess(false), 3000);
    }, 1500);
  };

  const tabs = [
    { id: 'profile', label: 'My Profile', icon: Users, roles: ['admin', 'staff', 'tenant', 'landlord'] },
    { id: 'general', label: 'System General', icon: Globe, roles: ['admin', 'staff'] },
    { id: 'branding', label: 'System Branding', icon: Palette, roles: ['admin', 'staff'] },
    { id: 'permissions', label: 'System Permissions', icon: Users, roles: ['admin', 'staff'] },
    { id: 'security', label: 'Account Security', icon: ShieldCheck, roles: ['admin', 'staff', 'tenant', 'landlord'] },
  ].filter(tab => !tab.roles || (role && tab.roles.includes(role)));

  return (
    <DashboardLayout>
      <div className="space-y-8 max-w-5xl mx-auto">
        <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
          <div>
            <h2 className="text-2xl font-black text-slate-900 dark:text-white flex items-center gap-2">
              <Settings className="text-accent-gold" size={24} /> 
              {activeTab === 'profile' ? 'My Account Settings' : 'System Settings'}
            </h2>
            <p className="text-slate-500 text-sm font-medium">
              {activeTab === 'profile' ? 'Manage your personal information and preferences' : 'Configure organizational preferences and brand identity'}
            </p>
          </div>
          <button 
            onClick={handleSave}
            disabled={isSaving}
            className="flex items-center gap-2 px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl text-xs font-black uppercase tracking-widest hover:opacity-90 transition-all disabled:opacity-50"
          >
            {isSaving ? (
              <div className="w-4 h-4 border-2 border-current border-t-transparent rounded-full animate-spin"></div>
            ) : showSuccess ? (
              <CheckCircle2 size={16} className="text-green-500" />
            ) : (
              <Save size={16} />
            )}
            {isSaving ? 'Saving...' : showSuccess ? 'Saved' : 'Save Changes'}
          </button>
        </div>

        {/* Tab Navigation */}
        <div className="flex p-1 bg-slate-100 dark:bg-slate-900 rounded-2xl w-fit">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id as TabType)}
              className={`flex items-center gap-2 px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all ${
                activeTab === tab.id
                  ? 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white shadow-sm shadow-black/5'
                  : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
              }`}
            >
              <tab.icon size={16} />
              {tab.label}
            </button>
          ))}
        </div>

        <div className="animate-in fade-in slide-in-from-bottom-2 duration-300">
          {activeTab === 'profile' && <ProfileSettings />}
          {activeTab === 'general' && <GeneralSettings />}
          {activeTab === 'branding' && <BrandingSettings />}
          {activeTab === 'permissions' && <PermissionsSettings />}
          {activeTab === 'security' && <SecuritySettings />}
        </div>
      </div>
    </DashboardLayout>
  );
}

function ProfileSettings() {
  const [profile, setProfile] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [isUpdating, setIsUpdating] = useState(false);
  const [uploadingImage, setUploadingImage] = useState(false);

  useEffect(() => {
    async function loadProfile() {
      const { data: { session } } = await supabase.auth.getSession();
      if (session?.user) {
        const { data } = await supabase
          .from('profiles')
          .select('*')
          .eq('id', session.user.id)
          .single();
        setProfile(data);
      }
      setLoading(false);
    }
    loadProfile();
  }, []);

  const handleImageUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    setUploadingImage(true);
    const fileExt = file.name.split('.').pop();
    const filePath = `avatars/${profile.id}/${Math.random()}.${fileExt}`;

    const { error: uploadError } = await supabase.storage
      .from('profiles')
      .upload(filePath, file, { upsert: true });

    if (uploadError) {
      alert("Note: Please ensure you have created a 'profiles' bucket in Supabase Storage. Error: " + uploadError.message);
    } else {
      const { data: { publicUrl } } = supabase.storage
        .from('profiles')
        .getPublicUrl(filePath);

      const { error: updateError } = await supabase.from('profiles').update({ profile_image: publicUrl }).eq('id', profile.id);
      if (!updateError) {
        if (profile.role === 'tenant') {
          await supabase.from('tenants').update({ profile_image: publicUrl }).eq('user_id', profile.id);
        }
        setProfile({ ...profile, profile_image: publicUrl });
        alert('Image updated successfully!');
      }
    }
    setUploadingImage(false);
  };

  const handleUpdate = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsUpdating(true);
    const formData = new FormData(e.currentTarget as HTMLFormElement);
    const updates = {
      full_name: formData.get('full_name'),
      phone: formData.get('phone'),
    };

    const { error } = await supabase
      .from('profiles')
      .update(updates)
      .eq('id', profile.id);

    if (!error) {
       if (profile.role === 'tenant') {
         await supabase.from('tenants').update({ full_name: updates.full_name }).eq('user_id', profile.id);
       }
       setProfile({ ...profile, ...updates });
       alert('Profile updated successfully!');
    } else {
       alert(error.message);
    }
    setIsUpdating(false);
  };

  if (loading) return <div className="p-8 text-center">Loading profile...</div>;

  return (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
      <div className="md:col-span-2 space-y-6">
        <form onSubmit={handleUpdate} className="glass-card p-8 space-y-8">
          <div className="flex flex-col sm:flex-row gap-8 items-center border-b border-slate-100 dark:border-slate-800 pb-8">
             <div className="relative group">
                <div className="w-24 h-24 rounded-3xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 overflow-hidden border-4 border-white dark:border-slate-900 shadow-xl">
                  {profile?.profile_image ? (
                    <img src={profile.profile_image} alt="Profile" className="w-full h-full object-cover" />
                  ) : (
                    <Users size={40} />
                  )}
                  {uploadingImage && (
                    <div className="absolute inset-0 bg-black/50 flex items-center justify-center">
                       <div className="w-6 h-6 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                    </div>
                  )}
                </div>
                <label className="absolute -bottom-2 -right-2 p-2 bg-accent-gold text-white rounded-xl shadow-lg opacity-0 group-hover:opacity-100 transition-all cursor-pointer">
                  <Upload size={14} />
                  <input type="file" className="hidden" accept="image/*" onChange={handleImageUpload} disabled={uploadingImage} />
                </label>
             </div>
             <div className="space-y-1 text-center sm:text-left">
                <h3 className="text-xl font-black text-slate-900 dark:text-white">{profile?.full_name}</h3>
                <p className="text-xs text-slate-500 font-bold uppercase tracking-widest">{profile?.role} Account</p>
                <p className="text-[10px] text-slate-400 font-medium">Joined {new Date(profile?.created_at).toLocaleDateString()}</p>
             </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <div className="space-y-2">
              <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Full Name</label>
              <input 
                name="full_name"
                type="text" 
                defaultValue={profile?.full_name} 
                className="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 transition-all font-primary"
              />
            </div>
            <div className="space-y-2">
              <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Email Address</label>
              <div className="relative">
                <Mail className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={16} />
                <input 
                  type="email" 
                  disabled
                  value={profile?.email} 
                  className="w-full pl-11 pr-4 py-3 bg-slate-100 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold opacity-50 cursor-not-allowed font-primary"
                />
              </div>
            </div>
            <div className="space-y-2">
              <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Phone Number</label>
              <div className="relative">
                <Phone className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={16} />
                <input 
                  name="phone"
                  type="tel" 
                  defaultValue={profile?.phone} 
                  className="w-full pl-11 pr-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 transition-all font-primary"
                />
              </div>
            </div>
          </div>

          <div className="flex justify-end gap-3 pt-4">
             <button disabled={isUpdating} type="submit" className="px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl text-xs font-black uppercase tracking-widest hover:opacity-90 transition-all">
               {isUpdating ? 'Updating...' : 'Update Profile'}
             </button>
          </div>
        </form>
      </div>
      
      <div className="space-y-6">
        <div className="glass-card p-6 bg-accent-gold/5 border-accent-gold/20">
          <h4 className="text-sm font-black text-slate-900 dark:text-white mb-2">Profile Completeness</h4>
          <div className="w-full h-2 bg-slate-200 dark:bg-slate-800 rounded-full overflow-hidden mb-2">
            <div className="h-full bg-accent-gold w-[85%]"></div>
          </div>
          <p className="text-[10px] text-slate-500 font-bold uppercase tracking-widest">85% Complete</p>
        </div>
      </div>
    </div>
  );
}

function GeneralSettings() {
  return (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
      <div className="md:col-span-2 space-y-6">
        <div className="glass-card p-8 space-y-6">
          <h3 className="text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
            <Building2 size={18} className="text-accent-gold" /> Organization Profile
          </h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <div className="space-y-2">
              <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">System Name</label>
              <input 
                type="text" 
                defaultValue="PrimeLink Management System" 
                className="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 transition-all font-primary"
              />
            </div>
            <div className="space-y-2">
              <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Legal Entity Name</label>
              <input 
                type="text" 
                defaultValue="PrimeLink Real Estate Ltd" 
                className="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 transition-all font-primary"
              />
            </div>
            <div className="space-y-2">
              <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Primary Email</label>
              <div className="relative">
                <Mail className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={16} />
                <input 
                  type="email" 
                  defaultValue="ops@primelink.co.ke" 
                  className="w-full pl-11 pr-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 transition-all font-primary"
                />
              </div>
            </div>
            <div className="space-y-2">
              <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Primary Phone</label>
              <div className="relative">
                <Phone className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={16} />
                <input 
                  type="tel" 
                  defaultValue="+254 712 345 678" 
                  className="w-full pl-11 pr-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 transition-all font-primary"
                />
              </div>
            </div>
          </div>
        </div>

        <div className="glass-card p-8 space-y-6">
          <h3 className="text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
            <Globe size={18} className="text-accent-gold" /> Regional & Currency
          </h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <div className="space-y-2">
              <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Base Currency</label>
              <select className="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 transition-all appearance-none cursor-pointer">
                <option value="KES">Kenya Shillings (KSh)</option>
                <option value="USD">US Dollar ($)</option>
                <option value="EUR">Euro (€)</option>
              </select>
            </div>
            <div className="space-y-2">
              <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Timezone</label>
              <select className="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 transition-all appearance-none cursor-pointer">
                <option value="EAT">(GMT+03:00) East Africa Time</option>
                <option value="UTC">UTC</option>
              </select>
            </div>
          </div>
        </div>
      </div>
      
      <div className="space-y-6">
        <div className="glass-card p-6 bg-accent-gold/5 border-accent-gold/20">
          <h4 className="text-sm font-black text-slate-900 dark:text-white mb-2">Need a different currency?</h4>
          <p className="text-xs text-slate-600 dark:text-slate-400 font-medium leading-relaxed">System-wide multi-currency support requires Enterprise Plus plan activation.</p>
          <button className="mt-4 text-[10px] font-black text-accent-gold uppercase tracking-widest flex items-center gap-1 hover:gap-2 transition-all">
            Upgrade Plan <ChevronRight size={12} />
          </button>
        </div>
      </div>
    </div>
  );
}

function BrandingSettings() {
  return (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
      <div className="md:col-span-2 space-y-6">
        <div className="glass-card p-8 space-y-8">
          <h3 className="text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
            <Palette size={18} className="text-accent-gold" /> System Branding
          </h3>
          
          <div className="space-y-6">
            <div className="flex flex-col sm:flex-row gap-8 items-start">
              <div className="space-y-4 flex-1">
                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">System Logo</label>
                <div className="flex items-center gap-4">
                  <div className="w-20 h-20 bg-slate-100 dark:bg-slate-900 rounded-2xl border-2 border-dashed border-slate-200 dark:border-slate-800 flex items-center justify-center text-slate-400 group cursor-pointer hover:border-accent-gold transition-colors">
                    <Upload size={24} className="group-hover:scale-110 transition-transform" />
                  </div>
                  <div>
                    <button className="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white rounded-lg text-xs font-black uppercase tracking-widest hover:bg-slate-900 hover:text-white transition-all">
                      Choose File
                    </button>
                    <p className="text-[10px] text-slate-500 mt-2 font-medium">PNG or SVG, max 2MB. Recommended 512x512px.</p>
                  </div>
                </div>
              </div>

              <div className="space-y-4 flex-1">
                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Mobile Icon (PWA)</label>
                <div className="flex items-center gap-4">
                  <div className="w-20 h-20 bg-slate-100 dark:bg-slate-900 rounded-2xl border-2 border-dashed border-slate-200 dark:border-slate-800 flex items-center justify-center text-slate-400 group cursor-pointer hover:border-accent-gold transition-colors">
                    <Smartphone size={24} className="group-hover:scale-110 transition-transform" />
                  </div>
                  <div>
                    <button className="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white rounded-lg text-xs font-black uppercase tracking-widest hover:bg-slate-900 hover:text-white transition-all">
                      Choose File
                    </button>
                    <p className="text-[10px] text-slate-500 mt-2 font-medium">Square PNG, 192x192px minimum.</p>
                  </div>
                </div>
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-8 pt-6 border-t border-slate-100 dark:border-slate-800">
               <div className="space-y-4">
                  <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Primary Color</label>
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-accent-gold shadow-lg ring-4 ring-accent-gold/10"></div>
                    <input 
                      type="text" 
                      defaultValue="#c6a35f" 
                      className="flex-1 px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold font-primary"
                    />
                  </div>
               </div>
               <div className="space-y-4">
                  <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Secondary Color</label>
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-slate-900 dark:bg-white shadow-lg ring-4 ring-slate-900/10 dark:ring-white/10"></div>
                    <input 
                      type="text" 
                      defaultValue="#0f172a" 
                      className="flex-1 px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold font-primary"
                    />
                  </div>
               </div>
            </div>
          </div>
        </div>
      </div>

      <div className="glass-card p-6 flex flex-col items-center text-center justify-center">
        <div className="w-32 h-32 bg-slate-100 dark:bg-slate-900 rounded-3xl mb-6 flex flex-col items-center justify-center overflow-hidden border border-slate-200 dark:border-slate-800 shadow-xl">
           <div className="w-12 h-12 bg-accent-gold rounded-xl mb-2 flex items-center justify-center text-white font-black text-xl shadow-lg">P</div>
           <span className="text-xs font-black text-slate-900 dark:text-white">PrimeLink</span>
        </div>
        <h4 className="text-sm font-black text-slate-900 dark:text-white mb-2">Live Preview</h4>
        <p className="text-[10px] text-slate-500 font-bold uppercase tracking-widest leading-relaxed">
          This is how your logo and primary color will appear in the sidebar navigation and dashboards.
        </p>
      </div>
    </div>
  );
}

function PermissionsSettings() {
  const roles = [
    { name: 'Administrator', users: 2, level: 'All access', color: 'bg-red-500' },
    { name: 'Property Manager', users: 5, level: 'Management access', color: 'bg-blue-500' },
    { name: 'Agent', users: 12, level: 'Portfolio access only', color: 'bg-green-500' },
  ];

  return (
    <div className="space-y-8">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {roles.map(role => (
          <div key={role.name} className="glass-card p-6 flex items-center justify-between group cursor-pointer hover:translate-y-[-2px] transition-all">
            <div className="flex items-center gap-4">
              <div className={`w-2 h-10 rounded-full ${role.color}`}></div>
              <div>
                <h4 className="text-sm font-black text-slate-900 dark:text-white">{role.name}</h4>
                <p className="text-[10px] text-slate-500 font-bold uppercase tracking-widest">{role.users} Users Attached</p>
              </div>
            </div>
            <ChevronRight className="text-slate-300 group-hover:text-accent-gold transition-colors" size={18} />
          </div>
        ))}
        <div className="glass-card p-6 border-2 border-dashed border-slate-200 dark:border-slate-800 flex items-center justify-center text-slate-400 group cursor-pointer hover:border-accent-gold hover:text-accent-gold transition-all">
           <span className="text-xs font-black uppercase tracking-widest">+ Create New Role</span>
        </div>
      </div>

      <div className="glass-card overflow-hidden">
        <div className="p-8 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center bg-slate-50/50 dark:bg-slate-900/50">
          <div>
            <h3 className="text-lg font-black text-slate-900 dark:text-white">Role Permissions Explorer</h3>
            <p className="text-xs text-slate-500 font-medium">Configure granular access for <span className="text-accent-gold font-black">Administrator</span></p>
          </div>
          <select className="px-4 py-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-black uppercase tracking-widest focus:outline-hidden">
            <option>Change Target Role</option>
          </select>
        </div>
        <div className="p-8">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-12">
            <div className="space-y-6">
              <h4 className="text-[10px] font-black text-slate-400 uppercase tracking-widest flex items-center gap-2">
                <Building2 size={12} /> Property Management
              </h4>
              {[
                { label: 'View Portfolio', desc: 'Allow user to browse all properties', enabled: true },
                { label: 'Edit Asset Details', desc: 'Modify property information and units', enabled: true },
                { label: 'Manage Inventory', desc: 'Assign and audit property inventory', enabled: true },
              ].map(perm => (
                <div key={perm.label} className="flex items-center justify-between p-4 bg-slate-50 dark:bg-slate-950 rounded-2xl border border-slate-100 dark:border-slate-800">
                  <div>
                    <p className="text-sm font-bold text-slate-900 dark:text-white">{perm.label}</p>
                    <p className="text-[10px] text-slate-500 font-medium">{perm.desc}</p>
                  </div>
                  <Toggle enabled={perm.enabled} />
                </div>
              ))}
            </div>
            
            <div className="space-y-6">
              <h4 className="text-[10px] font-black text-slate-400 uppercase tracking-widest flex items-center gap-2">
                <ShieldCheck size={12} /> Financial & Payouts
              </h4>
              {[
                { label: 'Process Landlord Payouts', desc: 'Initiate and approve financial distributions', enabled: true },
                { label: 'View Revenue Reports', desc: 'Access comprehensive financial analytics', enabled: true },
                { label: 'Modify Fee Structure', desc: 'Change management fees and tax rules', enabled: false },
              ].map(perm => (
                <div key={perm.label} className="flex items-center justify-between p-4 bg-slate-50 dark:bg-slate-950 rounded-2xl border border-slate-100 dark:border-slate-800">
                  <div>
                    <p className="text-sm font-bold text-slate-900 dark:text-white">{perm.label}</p>
                    <p className="text-[10px] text-slate-500 font-medium">{perm.desc}</p>
                  </div>
                  <Toggle enabled={perm.enabled} />
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function SecuritySettings() {
  const [isUpdating, setIsUpdating] = useState(false);

  const handlePasswordChange = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsUpdating(true);
    const formData = new FormData(e.currentTarget as HTMLFormElement);
    const pass = formData.get('password') as string;
    const confirm = formData.get('confirm_password') as string;

    if (pass !== confirm) {
      alert("Passwords don't match");
      setIsUpdating(false);
      return;
    }

    const { error } = await supabase.auth.updateUser({ password: pass });
    if (error) {
      alert(error.message);
    } else {
      alert("Password updated successfully!");
      (e.target as HTMLFormElement).reset();
    }
    setIsUpdating(false);
  };

  return (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
      <div className="md:col-span-2 space-y-6">
        <div className="glass-card p-8 space-y-8">
          <div className="flex items-center justify-between">
            <h3 className="text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
              <Lock size={18} className="text-accent-gold" /> Authentication & Password
            </h3>
            <span className="px-2 py-1 bg-green-500/10 text-green-500 rounded text-[8px] font-black uppercase tracking-widest">Secure</span>
          </div>

          <form onSubmit={handlePasswordChange} className="space-y-6">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
              <div className="space-y-2">
                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">New Password</label>
                <input 
                  name="password"
                  type="password" 
                  required
                  placeholder="••••••••••••"
                  className="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 transition-all font-primary"
                />
              </div>
              <div className="space-y-2">
                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Confirm Password</label>
                <input 
                  name="confirm_password"
                  type="password" 
                  required
                  placeholder="••••••••••••"
                  className="w-full px-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 transition-all font-primary"
                />
              </div>
            </div>
            <button disabled={isUpdating} type="submit" className="px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl text-xs font-black uppercase tracking-widest hover:opacity-90 transition-all">
               {isUpdating ? 'Saving...' : 'Update Password'}
            </button>
          </form>

          <div className="space-y-6 pt-6 border-t border-slate-100 dark:border-slate-800">
            <div className="flex items-center justify-between p-6 bg-slate-50 dark:bg-slate-950 rounded-3xl border border-slate-100 dark:border-slate-800">
               <div className="flex items-center gap-4">
                 <div className="p-3 bg-white dark:bg-slate-900 rounded-2xl shadow-sm text-accent-gold">
                   <Smartphone size={24} />
                 </div>
                 <div>
                    <p className="text-sm font-black text-slate-900 dark:text-white">Two-Factor Authentication (2FA)</p>
                    <p className="text-xs text-slate-500 font-medium">Secure your account with SMS or Authenticator App</p>
                 </div>
               </div>
               <Toggle enabled={true} />
            </div>
          </div>
        </div>
      </div>

      <div className="space-y-6">
        <div className="glass-card p-6 border-slate-200 dark:border-slate-800 bg-slate-50/50">
          <h4 className="text-sm font-black text-slate-900 dark:text-white mb-2">Password Policy</h4>
          <ul className="space-y-3">
             {['Minimum 12 Characters', 'Uppercase & Lowercase', 'Numbers', 'Special Characters'].map(rule => (
                <li key={rule} className="flex items-center gap-2 text-[10px] font-black text-slate-500 uppercase tracking-widest">
                   <CheckCircle2 size={12} className="text-green-500" /> {rule}
                </li>
             ))}
          </ul>
        </div>
      </div>
    </div>
  );
}

function Toggle({ enabled }: { enabled: boolean }) {
  const [isOn, setIsOn] = useState(enabled);
  return (
    <button 
      onClick={() => setIsOn(!isOn)}
      className={`w-12 h-6 rounded-full p-1 transition-colors duration-200 focus:outline-hidden ${isOn ? 'bg-accent-gold' : 'bg-slate-200 dark:bg-slate-800'}`}
    >
      <div className={`w-4 h-4 rounded-full bg-white transition-transform duration-200 ${isOn ? 'translate-x-6' : 'translate-x-0'}`}></div>
    </button>
  );
}
