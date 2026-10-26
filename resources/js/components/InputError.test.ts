import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import InputError from '@/components/InputError.vue';

describe('InputError', () => {
    it('shows the message', () => {
        const wrapper = mount(InputError, {
            props: { message: 'The email field is required.' },
        });

        expect(wrapper.text()).toBe('The email field is required.');
        expect(wrapper.isVisible()).toBe(true);
    });

    it('is hidden when there is no message', () => {
        const wrapper = mount(InputError, { attachTo: document.body });

        expect(wrapper.isVisible()).toBe(false);

        wrapper.unmount();
    });
});
