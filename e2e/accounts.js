// @ts-check

/*
 * Accounts the browser suite creates through the product rather than the seeder — ADR-026, as amended by ADR-039.
 *
 * ⚠️ A THROWAWAY CREDENTIAL FOR A THROWAWAY DATABASE. `e2e/global-setup.js` pipes it to
 * `kitsune:blueprint apply --owner-password-stdin`, so it has to pass the first owner's rules (fifteen characters at
 * least), and it is never an argument or an environment variable, as the command requires of any real one.
 */
module.exports = {
    BLOG_OWNER: { email: 'blog-owner@kitsune.test', password: 'inkwell-first-owner-e2e' },
};
