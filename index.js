panel.plugin('akibeo/cacher', {
  components: {
    'k-cacher-view': {
      template: `
        <k-panel-inside>
          <k-header>Cache Manager</k-header>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <k-box theme="info">
              <k-text>
                <h3 style="margin-top: 0;">File Cache</h3>
                <p><strong>Path:</strong> {{ cachePath }}</p>
                <p v-if="stats"><strong>Files:</strong> {{ stats.file_count }}</p>
                <p v-if="stats" style="margin-bottom: 0;"><strong>Size:</strong> {{ stats.cache_size }}</p>
              </k-text>
            </k-box>

            <k-box :theme="redisEnabled ? 'info' : 'notice'">
              <k-text>
                <h3 style="margin-top: 0;">Redis Pages Cache</h3>
                <p><strong>Status:</strong> {{ redisEnabled ? 'Enabled' : 'Not configured' }}</p>
                <template v-if="stats && stats.redis_enabled">
                  <p v-if="stats.redis_key_count > 0"><strong>Keys:</strong> {{ stats.redis_key_count }}</p>
                  <p v-if="stats.redis_key_count > 0" style="margin-bottom: 0;"><strong>Memory:</strong> {{ stats.redis_memory_usage }}</p>
                  <p v-else style="margin-bottom: 0; color: var(--color-text-dimmed);">No cached pages</p>
                </template>
              </k-text>
            </k-box>
          </div>

          <div style="margin-top: 2rem; display: flex; flex-wrap: wrap; align-items: center; gap: 1rem;">
            <k-button icon="trash" theme="negative" variant="filled" :disabled="loading" @click="clearCache">
              {{ clearing ? 'Clearing cache…' : 'Clear Cache' }}
            </k-button>
            <k-button
              icon="bolt"
              variant="filled"
              :disabled="loading || !pagesCacheActive"
              :title="pagesCacheActive ? 'Request every published page so it is stored in the pages cache' : 'Pages cache is off'"
              @click="warmup"
            >
              {{ progress ? 'Warming up…' : 'Warm Up Cache' }}
            </k-button>
            <k-button icon="refresh" variant="filled" :disabled="loading" @click="refreshStats">
              Refresh Stats
            </k-button>
            <span v-if="!pagesCacheActive" style="color: var(--color-text-dimmed); font-size: var(--text-sm);">
              Pages cache is off (cache.pages), nothing to warm up
            </span>
          </div>

          <div v-if="progress" style="margin-top: 1rem;">
            <k-progress :value="progressPercent" />
            <p style="margin-top: 0.5rem; color: var(--color-text-dimmed); font-size: var(--text-sm);">
              Warming {{ progress.done }} / {{ progress.total }} pages…
            </p>
          </div>

          <template v-if="stats && stats.namespaces && stats.namespaces.length">
            <k-headline style="margin-top: 2.5rem;">Cache namespaces</k-headline>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 0.75rem;">
              <k-box v-for="ns in stats.namespaces" :key="ns.name" theme="info">
                <k-text>
                  <h3 style="margin-top: 0;">{{ ns.name }}</h3>
                  <p><strong>Type:</strong> {{ ns.type }}</p>
                  <p><strong>{{ ns.type === 'redis' ? 'Keys' : 'Files' }}:</strong> {{ ns.file_count }}</p>
                  <p><strong>Size:</strong> {{ ns.cache_size }}</p>
                </k-text>
                <k-button icon="trash" variant="filled" size="sm" :disabled="loading" @click="clearNamespace(ns.name)">
                  Clear {{ ns.name }}
                </k-button>
              </k-box>
            </div>
          </template>

          <k-box v-if="result" :theme="result.success ? 'positive' : 'negative'" style="margin-top: 2rem;">
            <k-text>
              <h3 style="margin-top: 0;">{{ result.title || (result.success ? 'Cache cleared' : 'Cache clearing failed') }}</h3>
              <ul v-if="result.cleared && result.cleared.length">
                <li v-for="item in result.cleared" :key="item">{{ item }}</li>
              </ul>
              <ul v-if="result.errors && result.errors.length">
                <li v-for="error in result.errors" :key="error">{{ error }}</li>
              </ul>
            </k-text>
          </k-box>
        </k-panel-inside>
      `,
      props: {
        cachePath: String,
        redisEnabled: Boolean,
        pagesCacheActive: Boolean,
        namespaces: Array
      },
      data() {
        return {
          loading: false,
          clearing: false,
          progress: null,
          result: null,
          stats: null
        };
      },
      computed: {
        progressPercent() {
          if (!this.progress || this.progress.total === 0) {
            return 0;
          }

          return Math.round((this.progress.done / this.progress.total) * 100);
        }
      },
      mounted() {
        this.refreshStats();
      },
      methods: {
        async clearCache() {
          this.clearing = true;

          try {
            await this.run(() => this.$api.post('plugin/cacher/clear-cache'));
          } finally {
            this.clearing = false;
          }
        },
        async warmup() {
          this.loading = true;
          this.result = null;
          this.progress = { done: 0, total: 0 };

          try {
            const { urls, batch } = await this.$api.get('plugin/cacher/warmup-urls');
            const size = Math.max(1, batch || 10);
            const errors = [];
            let warmed = 0;

            this.progress = { done: 0, total: urls.length };

            for (let i = 0; i < urls.length; i += size) {
              const chunk = urls.slice(i, i + size);
              const result = await this.$api.post('plugin/cacher/warmup', { urls: chunk });

              warmed += result.warmed || 0;
              errors.push(...(result.errors || []));
              this.progress = { done: Math.min(i + size, urls.length), total: urls.length };
            }

            this.result = {
              success: errors.length === 0,
              title: errors.length === 0 ? 'Cache warmed' : 'Cache warmed with errors',
              cleared: ['Cache warmed (' + warmed + ' of ' + urls.length + ' pages)'],
              errors: errors
            };

            await this.refreshStats();
          } catch (error) {
            this.result = {
              success: false,
              title: 'Cache warming failed',
              cleared: [],
              errors: [error.message || 'Request failed']
            };
          } finally {
            this.progress = null;
            this.loading = false;
          }
        },
        async clearNamespace(name) {
          await this.run(() => this.$api.post('plugin/cacher/clear-namespace/' + encodeURIComponent(name)));
        },
        async run(request) {
          this.loading = true;
          this.result = null;

          try {
            this.result = await request();
            await this.refreshStats();
          } catch (error) {
            this.result = {
              success: false,
              cleared: [],
              errors: [error.message || 'Request failed']
            };
          } finally {
            this.loading = false;
          }
        },
        async refreshStats() {
          try {
            this.stats = await this.$api.get('plugin/cacher/stats');
          } catch (error) {
            console.error('Failed to fetch cache stats:', error);
          }
        }
      }
    }
  }
});
