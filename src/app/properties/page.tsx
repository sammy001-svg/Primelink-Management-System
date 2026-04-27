'use client';

import { useState, useEffect } from 'react';
import DashboardLayout from '@/components/DashboardLayout';
import PropertyCard from '@/components/PropertyCard';
import { supabase } from '@/lib/supabase';
import { Plus, Search, Filter, X, Check, Building2, MapPin, Hash, Trash2, Edit2, Image as ImageIcon, UploadCloud } from 'lucide-react';

export default function PropertiesPage() {
  const [properties, setProperties] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [role, setRole] = useState<string | null>(null);
  const [landlordId, setLandlordId] = useState<string | null>(null);
  const [showAddModal, setShowAddModal] = useState(false);
  const [editingProperty, setEditingProperty] = useState<any | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('All');

  useEffect(() => {
    async function init() {
      const { data: { session } } = await supabase.auth.getSession();
      if (session?.user) {
        setRole(session.user.user_metadata?.role || 'tenant');
        
        if (session.user.user_metadata?.role === 'landlord') {
           const { data: lData } = await supabase.from('landlords').select('id').eq('user_id', session.user.id).maybeSingle();
           setLandlordId(lData?.id || null);
        }
      }
      fetchProperties();
    }
    init();
  }, []);

  const fetchProperties = async () => {
    setLoading(true);
    const { data: { session } } = await supabase.auth.getSession();
    let query = supabase.from('properties').select('*, units(*)');
    
    if (session?.user.user_metadata?.role === 'landlord') {
       const { data: lData } = await supabase.from('landlords').select('id').eq('user_id', session.user.id).maybeSingle();
       if (lData) {
          query = query.eq('landlord_id', lData.id);
       }
    }

    const { data, error } = await query;
    if (!error) {
       // Map database structure to UI logic
       const mapped = data.map(p => ({
          ...p,
          type: p.property_type,
          listingType: p.listing_type,
          unitNo: p.units?.[0]?.unit_number || '',
          units: p.units?.map((u: any) => ({
             id: u.id,
             number: u.unit_number,
             type: u.unit_type,
             status: u.status,
             rent: u.rent_amount
          })) || []
       }));
       setProperties(mapped);
    }
    setLoading(false);
  };
  
  // Form State
  const [formData, setFormData] = useState<any>({
    title: '',
    location: '',
    price: 0,
    listingType: 'Rent',
    type: 'Apartment',
    status: 'Available',
    unitNo: '',
    floorNumber: '',
    unitType: '',
    description: '',
    area: 0,
    units: [],
    amenities: [],
    images: []
  });

  const [newUnit, setNewUnit] = useState<any>({ number: '', type: '1BR', status: 'Available' });

  const handleAddProperty = async () => {
    const { data: prop, error: propError } = await supabase
      .from('properties')
      .insert({
        title: formData.title,
        location: formData.location,
        price: formData.price,
        listing_type: formData.listingType,
        property_type: formData.type,
        status: formData.status,
        description: formData.description,
        area: formData.area,
        landlord_id: role === 'landlord' ? landlordId : null
      })
      .select()
      .single();

    if (!propError && prop) {
      if (formData.units && formData.units.length > 0) {
        const unitsToInsert = formData.units.map((u: any) => ({
          property_id: prop.id,
          unit_number: u.number,
          unit_type: u.type,
          status: u.status,
          rent_amount: u.rent || 0
        }));
        await supabase.from('units').insert(unitsToInsert);
      }
      fetchProperties();
      setShowAddModal(false);
      resetForm();
    } else {
      alert(propError?.message || "Error adding property");
    }
  };

  const handleUpdateProperty = async () => {
    if (!editingProperty) return;
    
    const { error: propError } = await supabase
      .from('properties')
      .update({
        title: editingProperty.title,
        location: editingProperty.location,
        price: editingProperty.price,
        listing_type: editingProperty.listingType,
        property_type: editingProperty.type,
        status: editingProperty.status,
        description: editingProperty.description,
        area: editingProperty.area
      })
      .eq('id', editingProperty.id);

    if (!propError) {
      fetchProperties();
      setEditingProperty(null);
    } else {
      alert(propError.message);
    }
  };

  const resetForm = () => {
    setFormData({ 
      title: '', location: '', price: 0, listingType: 'Rent', type: 'Apartment', status: 'Available', 
      unitNo: '', floorNumber: '', unitType: '', description: '', area: 0, units: [], amenities: [], images: [] 
    });
  };

  const addUnitToForm = () => {
    if (!newUnit.number) return;
    const unit: any = {
      id: `u-${Date.now()}`,
      number: newUnit.number,
      type: newUnit.type as any || '1BR',
      status: newUnit.status as any || 'Available',
      rent: newUnit.rent
    };
    
    if (editingProperty) {
      setEditingProperty({ ...editingProperty, units: [...editingProperty.units, unit] });
    } else {
      setFormData({ ...formData, units: [...(formData.units || []), unit] });
    }
    setNewUnit({ number: '', type: '1BR', status: 'Available' });
  };

  const removeUnit = (unitId: string) => {
    if (editingProperty) {
      setEditingProperty({ ...editingProperty, units: editingProperty.units.filter(u => u.id !== unitId) });
    } else {
      setFormData({ ...formData, units: (formData.units || []).filter(u => u.id !== unitId) });
    }
  };

  const filteredProperties = properties.filter(prop => {
    const matchesSearch = prop.title.toLowerCase().includes(searchQuery.toLowerCase()) || 
                          prop.location.toLowerCase().includes(searchQuery.toLowerCase());
    const matchesCategory = categoryFilter === 'All' || prop.type === categoryFilter;
    return matchesSearch && matchesCategory;
  });

  return (
    <DashboardLayout>
      <div className="space-y-6">
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div className="flex w-full sm:w-auto gap-4 flex-col sm:flex-row">
            <div className="relative w-full sm:w-80">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
              <input 
                type="text" 
                placeholder="Search properties..." 
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-accent-gold/20 focus:border-accent-gold transition-all"
              />
            </div>
            <div className="relative w-full sm:w-48">
              <Filter className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
              <select 
                value={categoryFilter}
                onChange={(e) => setCategoryFilter(e.target.value)}
                className="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-accent-gold/20 focus:border-accent-gold transition-all appearance-none cursor-pointer text-slate-700 dark:text-slate-300 font-medium"
              >
                <option value="All">All Categories</option>
                <option value="Land">Land</option>
                <option value="Apartment">Apartment</option>
                <option value="Villa">Villa</option>
                <option value="Single Room">Single Room</option>
                <option value="Shop">Shop</option>
                <option value="Office">Office</option>
                <option value="Other">Other</option>
              </select>
            </div>
          </div>
          <div className="flex w-full sm:w-auto">
            <button 
              onClick={() => setShowAddModal(true)}
              className="flex-1 sm:flex-none flex items-center justify-center gap-2 px-4 py-2.5 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-medium hover:opacity-90 transition-opacity"
            >
              <Plus size={18} />
              Add Property
            </button>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {loading ? (
            <div className="col-span-full py-20 flex justify-center">
               <div className="w-10 h-10 border-4 border-accent-gold border-t-transparent rounded-full animate-spin"></div>
            </div>
          ) : filteredProperties.length > 0 ? (
            filteredProperties.map((prop) => (
              <div key={prop.id} className="relative group">
                <PropertyCard property={prop} />
                <button 
                  onClick={() => setEditingProperty(prop)}
                  className="absolute top-4 left-4 p-2 bg-white/90 dark:bg-slate-900/90 backdrop-blur-md rounded-xl text-slate-600 dark:text-slate-400 hover:text-accent-gold transition-colors opacity-0 group-hover:opacity-100 shadow-lg z-10"
                >
                  <Edit2 size={16} />
                </button>
              </div>
            ))
          ) : (
            <div className="col-span-full p-12 text-center text-slate-500 glass-card font-medium">
              No properties found matching your criteria.
            </div>
          )}
        </div>
      </div>

      {/* ADD/EDIT MODAL */}
      {(showAddModal || editingProperty) && (
        <div className="fixed inset-0 z-100 flex items-center justify-center p-4 bg-slate-950/40 backdrop-blur-sm animate-in fade-in duration-300">
          <div className="glass-card w-full max-w-4xl max-h-[90vh] overflow-hidden bg-white dark:bg-slate-900 shadow-2xl flex flex-col">
            <div className="p-6 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center bg-slate-50/50 dark:bg-slate-950/50">
              <h3 className="text-xl font-bold text-slate-900 dark:text-white">
                {showAddModal ? 'Add New Property' : 'Edit Property asset'}
              </h3>
              <button 
                onClick={() => { setShowAddModal(false); setEditingProperty(null); resetForm(); }}
                className="p-2 transition-colors text-slate-400 hover:text-slate-900 dark:hover:text-white bg-white dark:bg-slate-800 rounded-xl shadow-sm"
              >
                <X size={20} />
              </button>
            </div>

            <div className="flex-1 overflow-y-auto p-6 custom-scrollbar">
              <div className="max-w-2xl mx-auto">
                {/* General Info */}
                <div className="space-y-6">
                  <h4 className="text-xs font-black uppercase tracking-[0.2em] text-accent-gold">General Details</h4>
                  
                  <div className="form-group pb-4 border-b border-slate-100 dark:border-slate-800">
                    <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Listing Type</label>
                    <div className="flex gap-4">
                      <label className={`flex-1 py-3 px-4 rounded-xl border-2 text-center font-bold cursor-pointer transition-all ${
                        (showAddModal ? formData.listingType : editingProperty?.listingType) === 'Rent'
                          ? 'border-accent-gold bg-accent-gold/5 text-accent-gold'
                          : 'border-slate-200 dark:border-slate-800 text-slate-500 hover:border-slate-300 dark:hover:border-slate-700'
                      }`}>
                        <input 
                          type="radio" 
                          name="listingType" 
                          value="Rent" 
                          className="hidden"
                          checked={(showAddModal ? formData.listingType : editingProperty?.listingType) === 'Rent'}
                          onChange={(e) => showAddModal ? setFormData({...formData, listingType: 'Rent'}) : setEditingProperty({...editingProperty!, listingType: 'Rent'})}
                        />
                        For Rent
                      </label>
                      <label className={`flex-1 py-3 px-4 rounded-xl border-2 text-center font-bold cursor-pointer transition-all ${
                        (showAddModal ? formData.listingType : editingProperty?.listingType) === 'Sale'
                          ? 'border-accent-gold bg-accent-gold/5 text-accent-gold'
                          : 'border-slate-200 dark:border-slate-800 text-slate-500 hover:border-slate-300 dark:hover:border-slate-700'
                      }`}>
                        <input 
                          type="radio" 
                          name="listingType" 
                          value="Sale" 
                          className="hidden"
                          checked={(showAddModal ? formData.listingType : editingProperty?.listingType) === 'Sale'}
                          onChange={(e) => showAddModal ? setFormData({...formData, listingType: 'Sale'}) : setEditingProperty({...editingProperty!, listingType: 'Sale'})}
                        />
                        For Sale
                      </label>
                    </div>
                  </div>

                  <div className="form-group">
                    <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Property Title</label>
                    <input 
                      type="text" 
                      placeholder="e.g., Elysian Heights"
                      className="form-input"
                      value={showAddModal ? formData.title : editingProperty?.title}
                      onChange={(e) => showAddModal ? setFormData({...formData, title: e.target.value}) : setEditingProperty({...editingProperty!, title: e.target.value})}
                    />
                  </div>

                  <div className="form-group">
                    <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Location</label>
                    <input 
                      type="text" 
                      placeholder="e.g., 123 Prime Street, Downtown"
                      className="form-input"
                      value={showAddModal ? formData.location : editingProperty?.location}
                      onChange={(e) => showAddModal ? setFormData({...formData, location: e.target.value}) : setEditingProperty({...editingProperty!, location: e.target.value})}
                    />
                  </div>

                  <div className="grid grid-cols-2 gap-6">
                    <div className="form-group">
                      <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">
                        {(showAddModal ? formData.listingType : editingProperty?.listingType) === 'Sale' ? 'One-off Price (KSh)' : 'Monthly Rent (KSh)'}
                      </label>
                      <input 
                        type="number" 
                        className="form-input font-black text-lg text-accent-gold"
                        value={showAddModal ? formData.price : editingProperty?.price}
                        onChange={(e) => showAddModal ? setFormData({...formData, price: parseInt(e.target.value)}) : setEditingProperty({...editingProperty!, price: parseInt(e.target.value)})}
                      />
                    </div>
                    <div className="form-group">
                      <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Category</label>
                      <select 
                        className="form-select font-bold"
                        value={showAddModal ? formData.type : editingProperty?.type}
                        onChange={(e) => showAddModal ? setFormData({...formData, type: e.target.value as any}) : setEditingProperty({...editingProperty!, type: e.target.value as any})}
                      >
                        <option value="Apartment">Apartment</option>
                        <option value="Villa">Villa</option>
                        <option value="Single Room">Single Room</option>
                        <option value="Shop">Shop</option>
                        <option value="Office">Office</option>
                        <option value="Land">Land</option>
                        <option value="Other">Other</option>
                      </select>
                    </div>
                  </div>

                  <div className="grid grid-cols-3 gap-4">
                    <div className="form-group">
                      <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Unit No.</label>
                      <input 
                        type="text" 
                        placeholder="e.g. A-102"
                        className="form-input"
                        value={showAddModal ? formData.unitNo : editingProperty?.unitNo || ''}
                        onChange={(e) => showAddModal ? setFormData({...formData, unitNo: e.target.value}) : setEditingProperty({...editingProperty!, unitNo: e.target.value})}
                      />
                    </div>
                    <div className="form-group">
                      <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Floor No.</label>
                      <input 
                        type="text" 
                        placeholder="e.g. 5"
                        className="form-input"
                        value={showAddModal ? formData.floorNumber : editingProperty?.floorNumber || ''}
                        onChange={(e) => showAddModal ? setFormData({...formData, floorNumber: e.target.value}) : setEditingProperty({...editingProperty!, floorNumber: e.target.value})}
                      />
                    </div>
                    <div className="form-group">
                      <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Unit Type</label>
                      <select 
                        className="form-select"
                        value={showAddModal ? formData.unitType : editingProperty?.unitType || ''}
                        onChange={(e) => showAddModal ? setFormData({...formData, unitType: e.target.value}) : setEditingProperty({...editingProperty!, unitType: e.target.value})}
                      >
                        <option value="">Select...</option>
                        <option value="Studio">Studio</option>
                        <option value="1BR">1 Bedroom</option>
                        <option value="2BR">2 Bedroom</option>
                        <option value="3BR">3 Bedroom</option>
                        <option value="Penthouse">Penthouse</option>
                        <option value="Retail Space">Retail Space</option>
                        <option value="Warehouse Plot">Warehouse Plot</option>
                      </select>
                    </div>
                  </div>

                  <div className="form-group">
                    <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Description</label>
                    <textarea 
                      className="form-textarea text-sm"
                      placeholder="Describe the property asset features..."
                      value={showAddModal ? formData.description : editingProperty?.description}
                      onChange={(e) => showAddModal ? setFormData({...formData, description: e.target.value}) : setEditingProperty({...editingProperty!, description: e.target.value})}
                    />
                  </div>

                  {/* Image Upload */}
                  <div className="form-group pt-4 border-t border-slate-100 dark:border-slate-800">
                    <label className="form-label uppercase tracking-widest text-[10px] text-slate-400 mb-2">Property Images</label>
                    
                    {/* Preview Area */}
                    {(showAddModal ? formData.images?.length : editingProperty?.images?.length) ? (
                      <div className="flex gap-4 overflow-x-auto py-4 mb-2">
                         { (showAddModal ? formData.images : editingProperty?.images)?.map((img, i) => (
                            <div key={i} className="relative group shrink-0">
                               <img src={img} className="w-24 h-24 object-cover rounded-xl border border-slate-200 dark:border-slate-800" />
                               <button 
                                 type="button"
                                 onClick={() => {
                                    if (showAddModal && formData.images) {
                                       setFormData({...formData, images: formData.images.filter((_, index) => index !== i)});
                                    } else if (editingProperty && editingProperty.images) {
                                       setEditingProperty({...editingProperty, images: editingProperty.images.filter((_, index) => index !== i)});
                                    }
                                 }}
                                 className="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1 opacity-0 group-hover:opacity-100 transition-opacity"
                               >
                                 <X size={12} />
                               </button>
                            </div>
                         )) }
                      </div>
                    ) : null}

                    <label className="border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-2xl p-8 hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors flex flex-col items-center justify-center text-center cursor-pointer group">
                      <input 
                        type="file" 
                        accept="image/*" 
                        multiple 
                        className="hidden" 
                        onChange={(e) => {
                          if (e.target.files) {
                            const newImages = Array.from(e.target.files).map(f => URL.createObjectURL(f));
                            if (showAddModal) {
                               setFormData({...formData, images: [...(formData.images || []), ...newImages]});
                            } else if (editingProperty) {
                               setEditingProperty({...editingProperty, images: [...(editingProperty.images || []), ...newImages]});
                            }
                          }
                        }}
                      />
                      <div className="w-16 h-16 bg-slate-100 dark:bg-slate-800 text-slate-400 group-hover:text-accent-gold group-hover:bg-accent-gold/10 rounded-full flex items-center justify-center mb-4 transition-colors">
                        <UploadCloud size={24} />
                      </div>
                      <p className="font-bold text-slate-900 dark:text-white mb-1">Click to upload images</p>
                      <p className="text-xs text-slate-500">Supports JPG, PNG, WEBP (Max 5MB)</p>
                    </label>
                  </div>

                </div>


              </div>
            </div>

            <div className="p-8 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/50 flex justify-end gap-4">
              <button 
                onClick={() => { setShowAddModal(false); setEditingProperty(null); resetForm(); }}
                className="px-8 py-3 text-sm font-black uppercase tracking-widest text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors"
              >
                Discard
              </button>
              <button 
                onClick={showAddModal ? handleAddProperty : handleUpdateProperty}
                className="px-10 py-3.5 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] hover:shadow-accent-gold/20 active:translate-y-0 transition-all flex items-center gap-2"
              >
                <Check size={20} /> {showAddModal ? 'Register Asset' : 'Save Changes'}
              </button>
            </div>
          </div>
        </div>
      )}
    </DashboardLayout>
  );
}
