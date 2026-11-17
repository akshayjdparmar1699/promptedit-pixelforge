// resources/js/components/AppSidebar.vue
// Replace the mainNavItems definition with this (imports go at the top of <script setup>).

import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ChartColumn, Images, Sparkles, Wallet } from 'lucide-vue-next';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Generate', href: '/generate', icon: Sparkles },
    { title: 'History', href: '/generations', icon: Images },
    { title: 'Credits', href: '/billing', icon: Wallet },
    ...((page.props as any).auth?.user?.is_admin ? [{ title: 'Operator report', href: '/admin/report', icon: ChartColumn }] : []),
]);

// In the template, pass it as before: <NavMain :items="mainNavItems" />
